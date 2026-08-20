<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpAppInstance;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Support\McpArtifactEnvelope;
use Padosoft\AskMyDocsMcpPack\Artifacts\Artifact;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\McpToolResult;

final readonly class McpAppInstanceService
{
    public function __construct(
        private TenantContext $tenantContext,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
        private McpOAuthService $oauth,
        private ArtifactManagerContract $artifacts,
    ) {}

    /**
     * Persist only the browser-facing handle. Tool input/result and resource
     * URI remain encrypted at rest and are released after actor/conversation
     * authorization is repeated by {@see resolve()}.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>|null
     */
    public function capture(
        McpConnectionTool $tool,
        Model $actor,
        string $conversationId,
        array $arguments,
        McpToolResult $result,
        McpArtifactEnvelope $artifact,
    ): ?array {
        $resourceUri = $this->resourceUri($tool, $result);
        if ($resourceUri === null) {
            return null;
        }

        $toolResult = $result->toArray();
        if (! $this->fitsJsonLimit($toolResult, (int) config('connector-mcp.apps.max_tool_result_bytes', 524_288))) {
            return null;
        }

        $instance = McpAppInstance::query()->create([
            'tenant_id' => $this->tenantContext->current(),
            'mcp_connector_connection_id' => $tool->mcp_connector_connection_id,
            'mcp_connector_tool_id' => $tool->getKey(),
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => (string) $actor->getKey(),
            'conversation_id' => $conversationId,
            'resource_uri' => $resourceUri,
            'tool_input' => $arguments,
            'tool_result' => $toolResult,
            'expires_at' => now()->addSeconds(max(60, (int) config('connector-mcp.apps.retention_seconds', 86_400))),
        ]);

        return [
            'id' => $instance->public_id,
            'resource_uri' => $resourceUri,
            'fallback' => $artifact->llmText !== ''
                ? $artifact->llmText
                : 'This MCP tool returned an interactive app.',
        ];
    }

    /** @return array<string,mixed> */
    public function resolve(string $publicId, Model $actor, string $conversationId): array
    {
        $instance = $this->authorized($publicId, $actor, $conversationId);
        $sandboxUrl = $this->sandboxUrl();
        if ($sandboxUrl === null) {
            return [
                'app_id' => $instance->public_id,
                'available' => false,
                'advanced_enabled' => false,
                'fallback' => 'Interactive MCP Apps require a dedicated sandbox origin.',
            ];
        }

        $snapshot = $instance->resource_snapshot;
        if (! is_array($snapshot)) {
            $snapshot = $this->fetchResource($instance);
            $instance->forceFill(['resource_snapshot' => $snapshot])->save();
        }

        return $snapshot + [
            'app_id' => $instance->public_id,
            'available' => true,
            'advanced_enabled' => $this->advancedEnabled(),
            'sandbox_url' => $sandboxUrl,
            'tool_input' => $instance->tool_input,
            'tool_result' => $instance->tool_result,
        ];
    }

    public function advancedEnabled(): bool
    {
        return (bool) config('connector-mcp.apps.advanced_enabled', false);
    }

    /** @param array<string,mixed> $context */
    public function replaceModelContext(McpAppInstance $instance, array $context): void
    {
        $content = [];
        foreach (array_slice(is_array($context['content'] ?? null) ? $context['content'] : [], 0, 32) as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text' || ! is_string($block['text'] ?? null)) {
                continue;
            }
            $content[] = [
                'type' => 'text',
                'text' => $block['text'],
            ];
        }
        $structured = $context['structuredContent'] ?? null;
        if ($structured !== null && (! is_array($structured) || (array_is_list($structured) && $structured !== []))) {
            throw new \InvalidArgumentException('MCP App structured context must be an object.');
        }
        $normalized = array_filter([
            'content' => $content !== [] ? $content : null,
            'structuredContent' => is_array($structured) ? $structured : null,
        ], static fn (mixed $value): bool => $value !== null);
        if (! $this->fitsJsonLimit(
            $normalized,
            (int) config('connector-mcp.apps.max_model_context_bytes', 65_536),
        )) {
            throw new \InvalidArgumentException('MCP App model context exceeded the configured limit.');
        }

        $instance->forceFill(['model_context' => $normalized !== [] ? $normalized : null])->save();
    }

    public function promptContext(McpAppInstance $instance): ?string
    {
        $context = $instance->model_context;
        if (! is_array($context) || $context === []) {
            return null;
        }
        $parts = [];
        foreach (is_array($context['content'] ?? null) ? $context['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }
        if (is_array($context['structuredContent'] ?? null)) {
            $json = json_encode($context['structuredContent'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $parts[] = $json;
            }
        }
        $value = trim(implode("\n\n", $parts));

        return $value !== '' ? $value : null;
    }

    /**
     * Materialize app-requested downloads as actor-scoped private artifacts.
     * Linked resources are fetched through the authorized MCP connection; the
     * browser never receives the remote URI as a direct download target.
     *
     * @param  list<array<string,mixed>>  $contents
     * @return list<array{name:string,mime_type:string,bytes:int,url:string}>
     */
    public function prepareDownloads(McpAppInstance $instance, Model $actor, array $contents): array
    {
        $maxItems = max(1, (int) config('connector-mcp.apps.max_download_items', 5));
        if ($contents === [] || count($contents) > $maxItems) {
            throw new \InvalidArgumentException('MCP App download item limit exceeded.');
        }
        $maxBytes = max(1, (int) config('connector-mcp.apps.max_download_bytes', 10_000_000));
        $totalBytes = 0;
        $created = [];
        $downloads = [];
        $client = null;

        try {
            foreach ($contents as $item) {
                $name = null;
                if (($item['type'] ?? null) === 'resource_link') {
                    $uri = is_string($item['uri'] ?? null) ? $item['uri'] : '';
                    if ($uri === '' || strlen($uri) > 2048 || preg_match('/[\r\n]/', $uri) === 1) {
                        throw new \InvalidArgumentException('MCP App download resource link is invalid.');
                    }
                    $name = is_string($item['name'] ?? null) ? $item['name'] : null;
                    $client ??= $this->clientFor($instance);
                    $resource = $this->readDownloadResource($client, $uri);
                } elseif (($item['type'] ?? null) === 'resource' && is_array($item['resource'] ?? null)) {
                    $resource = $item['resource'];
                } else {
                    throw new \InvalidArgumentException('MCP App downloads accept only embedded resources and resource links.');
                }

                $uri = is_string($resource['uri'] ?? null) ? $resource['uri'] : null;
                $mime = is_string($resource['mimeType'] ?? null) && $resource['mimeType'] !== ''
                    ? mb_substr($resource['mimeType'], 0, 255)
                    : (is_string($resource['text'] ?? null) ? 'text/plain' : 'application/octet-stream');
                if (is_string($resource['blob'] ?? null)) {
                    $bytes = base64_decode($resource['blob'], true);
                    if (! is_string($bytes)) {
                        throw new \InvalidArgumentException('MCP App download blob is not valid base64.');
                    }
                } elseif (is_string($resource['text'] ?? null)) {
                    $bytes = $resource['text'];
                } else {
                    throw new \InvalidArgumentException('MCP App download resource has no content.');
                }
                $totalBytes += strlen($bytes);
                if ($totalBytes > $maxBytes) {
                    throw new \InvalidArgumentException('MCP App downloads exceeded the configured byte limit.');
                }
                $artifact = $this->artifacts->create(
                    Artifact::make($this->downloadName($name, $uri))
                        ->mimeType($mime)
                        ->contents($bytes)
                        ->metadata([
                            'source' => 'mcp_app',
                            'app_id' => $instance->public_id,
                            'connection_id' => $instance->connection->public_id,
                        ]),
                    $this->tenantContext->current(),
                    (string) $actor->getKey(),
                );
                $created[] = (string) $artifact->getKey();
                $downloads[] = [
                    'name' => (string) $artifact->name,
                    'mime_type' => (string) $artifact->mime_type,
                    'bytes' => (int) $artifact->size_bytes,
                    'url' => $this->artifacts->temporaryUrl(
                        (string) $artifact->getKey(),
                        $this->tenantContext->current(),
                        (string) $actor->getKey(),
                    ),
                ];
            }

            return $downloads;
        } catch (\Throwable $e) {
            foreach ($created as $artifactId) {
                try {
                    $this->artifacts->delete(
                        $artifactId,
                        $this->tenantContext->current(),
                        (string) $actor->getKey(),
                    );
                } catch (\Throwable) {
                }
            }

            throw $e;
        }
    }

    public function authorized(string $publicId, Model $actor, string $conversationId): McpAppInstance
    {
        $id = DB::table('mcp_connector_app_instances')
            ->where('tenant_id', $this->tenantContext->current())
            ->where('public_id', $publicId)
            ->where('actor_type', $actor->getMorphClass())
            ->where('actor_id', (string) $actor->getKey())
            ->where('conversation_id', $conversationId)
            ->where('expires_at', '>', now())
            ->value('id');
        if (! is_int($id)) {
            throw new ModelNotFoundException;
        }

        $instance = McpAppInstance::query()
            ->with(['connection.server', 'tool'])
            ->findOrFail($id);
        if ($instance->connection->status !== McpConnection::STATUS_ACTIVE) {
            throw new ModelNotFoundException;
        }

        return $instance;
    }

    public function callableTool(McpAppInstance $instance, string $remoteName): McpConnectionTool
    {
        $toolId = McpConnectionTool::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mcp_connector_connection_id', $instance->connection->getKey())
            ->where('remote_name', $remoteName)
            ->where('enabled', true)
            ->whereNull('removed_at')
            ->value('id');
        if (! is_int($toolId)) {
            throw new ModelNotFoundException;
        }
        $tool = McpConnectionTool::query()->findOrFail($toolId);
        if (! $this->isAppVisible($tool)) {
            throw new ModelNotFoundException;
        }

        return $tool;
    }

    /** @return array<string,mixed> */
    private function fetchResource(McpAppInstance $instance): array
    {
        $client = $this->clientFor($instance);
        $response = $client->readResource($instance->resource_uri);
        $contents = is_array($response['contents'] ?? null) ? $response['contents'] : [];
        $resource = null;
        foreach ($contents as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $uri = $candidate['uri'] ?? null;
            if ($uri === $instance->resource_uri || ($uri === null && $resource === null)) {
                $resource = $candidate;
                if ($uri === $instance->resource_uri) {
                    break;
                }
            }
        }
        if (! is_array($resource)) {
            throw new \RuntimeException('MCP Apps resources/read returned no matching content.');
        }

        $mimeType = is_string($resource['mimeType'] ?? null) ? strtolower(trim($resource['mimeType'])) : '';
        $accepted = array_map('strtolower', (array) config('connector-mcp.apps.accepted_mime_types', [
            'text/html;profile=mcp-app',
            'text/html+skybridge',
        ]));
        if (! in_array($mimeType, $accepted, true)) {
            throw new \RuntimeException('MCP App resource has an unsupported MIME type.');
        }
        $html = $resource['text'] ?? null;
        if (! is_string($html) || $html === '') {
            throw new \RuntimeException('MCP App resource omitted its HTML content.');
        }
        if (strlen($html) > max(1, (int) config('connector-mcp.apps.max_html_bytes', 1_000_000))) {
            throw new \RuntimeException('MCP App resource exceeded the configured HTML limit.');
        }

        $meta = is_array($resource['_meta'] ?? null) ? $resource['_meta'] : [];
        $ui = is_array($meta['ui'] ?? null) ? $meta['ui'] : [];

        return [
            'resource_uri' => $instance->resource_uri,
            'mime_type' => $mimeType,
            'html' => $html,
            'csp' => $this->csp($ui, $meta),
            'permissions' => $this->permissions($ui),
            'prefers_border' => ($ui['prefersBorder'] ?? $meta['openai/widgetPrefersBorder'] ?? false) === true,
            'description' => is_string($meta['openai/widgetDescription'] ?? null)
                ? mb_substr($meta['openai/widgetDescription'], 0, 1024)
                : null,
        ];
    }

    private function clientFor(McpAppInstance $instance): McpClient
    {
        $connection = $instance->connection;
        if ($connection->server->auth_mode === 'oauth') {
            $this->oauth->refreshIfNeeded($connection);
        }

        return McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
    }

    /** @return array<string,mixed> */
    private function readDownloadResource(McpClient $client, string $uri): array
    {
        $response = $client->readResource($uri);
        foreach (is_array($response['contents'] ?? null) ? $response['contents'] : [] as $resource) {
            if (is_array($resource) && ($resource['uri'] ?? null) === $uri) {
                return $resource;
            }
        }

        throw new \RuntimeException('MCP App download resource was not returned by the server.');
    }

    private function downloadName(?string $requested, ?string $uri): string
    {
        if (is_string($requested) && trim($requested) !== '') {
            return mb_substr(trim($requested), 0, 180);
        }
        $path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null;
        $name = is_string($path) ? basename($path) : '';

        return $name !== '' && $name !== '/' ? mb_substr($name, 0, 180) : 'mcp-app-download';
    }

    private function resourceUri(McpConnectionTool $tool, McpToolResult $result): ?string
    {
        $toolMeta = is_array($tool->meta_json) ? $tool->meta_json : [];
        $descriptor = data_get($toolMeta, 'ui.resourceUri');
        if (! is_string($descriptor)) {
            $descriptor = $toolMeta['openai/outputTemplate'] ?? null;
        }
        $resultUri = data_get($result->meta, 'ui.resourceUri');
        if (! is_string($resultUri)) {
            $resultUri = $result->meta['openai/outputTemplate'] ?? null;
        }

        if (is_string($descriptor) && is_string($resultUri) && $descriptor !== $resultUri) {
            return null;
        }
        $uri = is_string($descriptor) ? $descriptor : (is_string($resultUri) ? $resultUri : null);
        if ($uri === null || strlen($uri) > 2048 || preg_match('/[\r\n]/', $uri) === 1) {
            return null;
        }
        $parts = parse_url($uri);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'ui') {
            return null;
        }

        return $uri;
    }

    /**
     * @param  array<string,mixed>  $ui
     * @param  array<string,mixed>  $meta
     * @return array<string,list<string>>
     */
    private function csp(array $ui, array $meta): array
    {
        $standard = is_array($ui['csp'] ?? null) ? $ui['csp'] : [];
        $legacy = is_array($meta['openai/widgetCSP'] ?? null) ? $meta['openai/widgetCSP'] : [];

        return [
            'connectDomains' => $this->origins($standard['connectDomains'] ?? $legacy['connect_domains'] ?? []),
            'resourceDomains' => $this->origins($standard['resourceDomains'] ?? $legacy['resource_domains'] ?? []),
            'frameDomains' => config('connector-mcp.apps.allow_nested_frames', false)
                ? $this->origins($standard['frameDomains'] ?? $legacy['frame_domains'] ?? [])
                : [],
            'baseUriDomains' => $this->origins($standard['baseUriDomains'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $ui
     * @return array<string,object>
     */
    private function permissions(array $ui): array
    {
        $requested = is_array($ui['permissions'] ?? null) ? $ui['permissions'] : [];
        $allowed = array_flip((array) config('connector-mcp.apps.allowed_permissions', []));
        $result = [];
        foreach (['camera', 'microphone', 'geolocation', 'clipboardWrite'] as $permission) {
            if (isset($allowed[$permission]) && array_key_exists($permission, $requested)) {
                $result[$permission] = (object) [];
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function origins(mixed $origins): array
    {
        if (! is_array($origins)) {
            return [];
        }

        $blocked = array_filter(array_map(
            fn (mixed $origin): ?string => is_string($origin) ? $this->origin($origin) : null,
            array_merge(
                (array) config('connector-mcp.apps.host_origins', []),
                [(string) config('connector-mcp.apps.sandbox_origin', '')],
            ),
        ));
        $result = [];
        foreach (array_slice($origins, 0, 32) as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $origin = $this->origin($candidate);
            if ($origin !== null && ! in_array($origin, $blocked, true)) {
                $result[] = $origin;
            }
        }

        return array_values(array_unique($result));
    }

    private function sandboxUrl(): ?string
    {
        $sandbox = $this->origin((string) config('connector-mcp.apps.sandbox_origin', ''));
        if ($sandbox === null) {
            return null;
        }
        $hostOrigins = array_filter(array_map(
            fn (mixed $origin): ?string => is_string($origin) ? $this->origin($origin) : null,
            (array) config('connector-mcp.apps.host_origins', []),
        ));
        if (in_array($sandbox, $hostOrigins, true)) {
            return null;
        }

        return $sandbox.'/'.ltrim((string) config('connector-mcp.apps.sandbox_path', '/mcp-apps/sandbox'), '/');
    }

    private function origin(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            return null;
        }
        if ($scheme === 'http' && ! config('connector-mcp.apps.allow_insecure_local', false)) {
            return null;
        }
        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    /** @param array<string,mixed> $payload */
    private function fitsJsonLimit(array $payload, int $limit): bool
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return false;
        }

        return strlen($json) <= max(1, $limit);
    }

    private function isAppVisible(McpConnectionTool $tool): bool
    {
        $meta = is_array($tool->meta_json) ? $tool->meta_json : [];
        $visibility = data_get($meta, 'ui.visibility');
        if (is_array($visibility)) {
            return in_array('app', $visibility, true);
        }
        if (array_key_exists('openai/widgetAccessible', $meta)) {
            return $meta['openai/widgetAccessible'] === true;
        }

        return true;
    }
}
