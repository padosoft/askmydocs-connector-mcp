<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionResource;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final readonly class McpResourceIngestionService
{
    public function __construct(
        private TenantContext $tenantContext,
        private ConnectorIngestionContract $ingestion,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
        private McpOAuthService $oauth,
        private McpResourceCatalogService $catalog,
    ) {}

    public function sync(ConnectorInstallation $installation): SyncResult
    {
        $connection = $this->connectionFor($installation);
        if ($connection->server->auth_mode === 'oauth') {
            $this->oauth->refreshIfNeeded($connection);
        }

        $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
        $negotiated = $client->negotiate();
        if (array_key_exists('resources', $negotiated->capabilities)) {
            $this->catalog->discover($connection, $client);
        } else {
            $this->catalog->markUnsupported($connection);
        }

        $projectKey = $this->projectKey($installation, $connection);
        $removed = $this->removeUnavailable($installation, $connection);
        $resourceIds = McpConnectionResource::query()
            ->where('tenant_id', $installation->tenant_id)
            ->where('mcp_connector_connection_id', $connection->getKey())
            ->where('enabled', true)
            ->whereNull('removed_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('connector-mcp.ingest.max_resources_per_sync', 500)) + 1)
            ->pluck('id');
        $maxResources = max(1, (int) config('connector-mcp.ingest.max_resources_per_sync', 500));
        if ($resourceIds->count() > $maxResources) {
            throw new \RuntimeException('Enabled MCP resources exceed the configured per-sync limit.');
        }

        $added = 0;
        $updated = 0;
        $errors = [];
        $syncBytes = 0;
        foreach ($resourceIds as $resourceId) {
            $resource = McpConnectionResource::query()
                ->where('tenant_id', $installation->tenant_id)
                ->where('mcp_connector_connection_id', $connection->getKey())
                ->findOrFail((int) $resourceId);
            try {
                $blocks = $this->contentBlocks($client->readResource($resource->uri), $resource);
                $resourceBytes = array_sum(array_column($blocks, 'size'));
                $syncBytes += $resourceBytes;
                if ($syncBytes > max(1, (int) config('connector-mcp.ingest.max_sync_bytes', 100_000_000))) {
                    throw new \RuntimeException('MCP resource sync exceeded the configured total byte limit.');
                }

                $contentHash = $this->contentHash($blocks);
                if ($resource->content_hash !== null && hash_equals($resource->content_hash, $contentHash)) {
                    $resource->forceFill(['ingest_error_json' => null])->save();

                    continue;
                }

                $prepared = $this->writeBlocks($installation, $connection, $resource, $projectKey, $blocks);
                $wasIngested = $resource->content_hash !== null;
                if ($wasIngested) {
                    $this->ingestion->softDeleteByRemoteId(
                        $installation,
                        'mcp_resource_key',
                        $this->resourceKey($connection, $resource),
                    );
                }
                foreach ($prepared as $document) {
                    $this->ingestion->dispatchIngestion(
                        projectKey: $projectKey,
                        relativePath: $document['relative_path'],
                        disk: $document['disk'],
                        title: $document['title'],
                        metadata: $document['metadata'],
                        mimeType: $document['mime_type'],
                        tenantId: (string) $installation->tenant_id,
                    );
                }

                $resource->forceFill([
                    'content_hash' => $contentHash,
                    'last_ingested_at' => now(),
                    'ingest_error_json' => null,
                ])->save();
                if ($wasIngested) {
                    $updated += count($prepared);
                } else {
                    $added += count($prepared);
                }
            } catch (\Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 2000);
                $resource->forceFill(['ingest_error_json' => [
                    'class' => $e::class,
                    'message' => $message,
                    'recorded_at' => now()->toIso8601String(),
                ]])->save();
                $errors[] = $resource->uri.': '.$message;
            }
        }

        return new SyncResult($added, $updated, $removed, $errors, Carbon::now());
    }

    private function connectionFor(ConnectorInstallation $installation): McpConnection
    {
        if ((string) $installation->tenant_id !== $this->tenantContext->current()) {
            throw new AuthorizationException('Connector installation is outside the active tenant.');
        }
        $publicId = data_get($installation->config_json, 'mcp_connection_public_id');
        if (! is_string($publicId) || $publicId === '') {
            throw new \InvalidArgumentException('MCP connector installation has no connection binding.');
        }

        $connection = McpConnection::query()
            ->with('server')
            ->where('tenant_id', $installation->tenant_id)
            ->where('mode', 'shared')
            ->where('public_id', $publicId)
            ->first();
        if ($connection === null) {
            throw new \InvalidArgumentException('The bound shared MCP connection no longer exists.');
        }
        if ($connection->status !== McpConnection::STATUS_ACTIVE) {
            throw new \RuntimeException('The bound MCP connection is not active.');
        }

        return $connection;
    }

    private function projectKey(ConnectorInstallation $installation, McpConnection $connection): string
    {
        foreach ([$installation->project_key, $connection->project_key, config('kb.ingest.default_project'), 'default'] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'default';
    }

    private function removeUnavailable(ConnectorInstallation $installation, McpConnection $connection): int
    {
        $removed = 0;
        while (true) {
            $resourceId = McpConnectionResource::query()
                ->where('tenant_id', $installation->tenant_id)
                ->where('mcp_connector_connection_id', $connection->getKey())
                ->whereNotNull('content_hash')
                ->where(function ($query): void {
                    $query->where('enabled', false)->orWhereNotNull('removed_at');
                })
                ->orderBy('id')
                ->value('id');
            if ($resourceId === null) {
                break;
            }
            $resource = McpConnectionResource::query()
                ->where('tenant_id', $installation->tenant_id)
                ->where('mcp_connector_connection_id', $connection->getKey())
                ->findOrFail((int) $resourceId);
            if ($this->ingestion->softDeleteByRemoteId(
                $installation,
                'mcp_resource_key',
                $this->resourceKey($connection, $resource),
            )) {
                $removed++;
            }
            $resource->forceFill([
                'content_hash' => null,
                'last_ingested_at' => null,
                'ingest_error_json' => null,
            ])->save();
        }

        return $removed;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array{bytes:string,mime_type:string,uri:string,size:int}>
     */
    private function contentBlocks(array $payload, McpConnectionResource $resource): array
    {
        $contents = $payload['contents'] ?? null;
        if (! is_array($contents) || $contents === []) {
            throw new \RuntimeException('MCP resources/read returned no content blocks.');
        }

        $blocks = [];
        $maxBytes = max(1, (int) config('connector-mcp.ingest.max_resource_bytes', 10_000_000));
        $total = 0;
        foreach ($contents as $content) {
            if (! is_array($content)) {
                throw new \RuntimeException('MCP resource content block is malformed.');
            }
            if (is_string($content['text'] ?? null)) {
                $bytes = $this->ingestion->redactContent($content['text']);
                $fallbackMime = 'text/plain';
            } elseif (is_string($content['blob'] ?? null)) {
                $decoded = base64_decode($content['blob'], true);
                if ($decoded === false) {
                    throw new \RuntimeException('MCP resource blob is not valid base64.');
                }
                $bytes = $decoded;
                $fallbackMime = 'application/octet-stream';
            } else {
                throw new \RuntimeException('MCP resource content block has neither text nor blob.');
            }
            $size = strlen($bytes);
            $total += $size;
            if ($total > $maxBytes) {
                throw new \RuntimeException('MCP resource exceeded the configured byte limit.');
            }
            $mime = $this->mimeType($content['mimeType'] ?? $resource->mime_type, $fallbackMime);
            $uri = is_string($content['uri'] ?? null) && $content['uri'] !== '' ? $content['uri'] : $resource->uri;
            $blocks[] = ['bytes' => $bytes, 'mime_type' => $mime, 'uri' => $uri, 'size' => $size];
        }

        return $blocks;
    }

    /**
     * @param  list<array{bytes:string,mime_type:string,uri:string,size:int}>  $blocks
     * @return list<array{relative_path:string,disk:string,title:string,mime_type:string,metadata:array<string,mixed>}>
     */
    private function writeBlocks(
        ConnectorInstallation $installation,
        McpConnection $connection,
        McpConnectionResource $resource,
        string $projectKey,
        array $blocks,
    ): array {
        $prepared = [];
        $baseTitle = $resource->title ?? $resource->name ?? $resource->uri;
        foreach ($blocks as $index => $block) {
            $relative = sprintf(
                '%s/connectors/mcp/%s/%s/%d.%s',
                $projectKey,
                $connection->public_id,
                $resource->uri_hash,
                $index + 1,
                $this->extension($block['mime_type']),
            );
            $paths = $this->ingestion->resolveKbSourcePath($relative);
            if (! Storage::disk($paths['disk'])->put($paths['absolute'], $block['bytes'])) {
                throw new \RuntimeException('Unable to persist MCP resource content on the KB disk.');
            }
            $prepared[] = [
                'relative_path' => $paths['relative'],
                'disk' => $paths['disk'],
                'title' => count($blocks) === 1 ? $baseTitle : $baseTitle.' #'.($index + 1),
                'mime_type' => $block['mime_type'],
                'metadata' => [
                    'connector' => 'mcp',
                    'installation_id' => $installation->getKey(),
                    'mcp_connection_id' => $connection->getKey(),
                    'mcp_connection_public_id' => $connection->public_id,
                    'mcp_server_id' => $connection->mcp_connector_server_id,
                    'mcp_resource_key' => $this->resourceKey($connection, $resource),
                    'mcp_resource_uri' => $resource->uri,
                    'mcp_content_uri' => $block['uri'],
                    'mcp_content_index' => $index,
                    'mcp_ingested_at' => now()->toIso8601String(),
                ],
            ];
        }

        return $prepared;
    }

    /** @param list<array{bytes:string,mime_type:string,uri:string,size:int}> $blocks */
    private function contentHash(array $blocks): string
    {
        $context = hash_init('sha256');
        foreach ($blocks as $block) {
            hash_update($context, $block['uri']."\0".$block['mime_type']."\0".$block['bytes']."\0");
        }

        return hash_final($context);
    }

    private function resourceKey(McpConnection $connection, McpConnectionResource $resource): string
    {
        return $connection->public_id.':'.$resource->uri_hash;
    }

    private function mimeType(mixed $candidate, string $fallback): string
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return $fallback;
        }
        $mime = strtolower(trim(explode(';', $candidate, 2)[0]));

        return preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/', $mime) === 1 ? $mime : $fallback;
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'text/markdown' => 'md',
            'text/html' => 'html',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'application/pdf' => 'pdf',
            'application/xml', 'text/xml' => 'xml',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            default => str_starts_with($mimeType, 'text/') ? 'txt' : 'bin',
        };
    }
}
