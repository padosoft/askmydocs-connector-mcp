<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsMcpPack\Contracts\McpProtocolAwareTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final readonly class McpDiscoveryService
{
    public function __construct(
        private TenantContext $tenantContext,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
        private McpOAuthService $oauth,
        private McpLocalToolName $names,
        private McpToolPolicy $policy,
    ) {}

    /** @return array{connection:McpConnection,tools:list<McpConnectionTool>,catalog_error:?string} */
    public function discover(McpConnection $connection): array
    {
        $this->assertTenant($connection);
        $connection->loadMissing('server');
        if ($connection->server->auth_mode === 'oauth') {
            $this->oauth->refreshIfNeeded($connection);
        }
        $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));

        try {
            $negotiated = $client->negotiate();
        } catch (\Throwable $e) {
            $this->markConnectionError($connection, 'negotiation', $e, $client);
            throw $e;
        }

        $connection->server->forceFill([
            'status' => McpServerDefinition::STATUS_ACTIVE,
            'negotiated_era' => $negotiated->era->value,
            'negotiated_version' => $negotiated->protocolVersion,
            'capabilities_json' => $negotiated->capabilities,
            'server_info_json' => $negotiated->serverInfo,
            'error_json' => null,
            'last_discovered_at' => now(),
        ])->save();
        $connection->forceFill([
            'status' => McpConnection::STATUS_ACTIVE,
            'error_json' => null,
            'last_discovered_at' => now(),
        ])->save();

        try {
            $remoteTools = $this->drainTools($client);
            $tools = $this->reconcile($connection, $remoteTools);
            $catalogError = null;
        } catch (\Throwable $e) {
            // Protocol/auth is healthy: an empty or failed catalog must not turn
            // the connection itself into a false-negative.
            $catalogError = $e->getMessage();
            $connection->forceFill(['error_json' => [
                'phase' => 'tools_list',
                'class' => $e::class,
                'message' => $catalogError,
            ]])->save();
            $tools = [];
        }

        return ['connection' => $connection->refresh(), 'tools' => $tools, 'catalog_error' => $catalogError];
    }

    /** @return list<array<string,mixed>> */
    private function drainTools(McpClient $client): array
    {
        $all = [];
        $cursor = null;
        $maxPages = max(1, (int) config('connector-mcp.http.max_catalog_pages', 20));
        for ($pageNumber = 0; $pageNumber < $maxPages; $pageNumber++) {
            $page = $client->listToolsPage($cursor);
            array_push($all, ...$page->items);
            $cursor = $page->nextCursor;
            if ($cursor === null) {
                return $all;
            }
        }

        throw new \RuntimeException('MCP tool catalog exceeded the configured page limit.');
    }

    /**
     * @param  list<array<string,mixed>>  $remoteTools
     * @return list<McpConnectionTool>
     */
    private function reconcile(McpConnection $connection, array $remoteTools): array
    {
        return DB::transaction(function () use ($connection, $remoteTools): array {
            $seen = [];
            $models = [];
            foreach ($remoteTools as $tool) {
                $remoteName = $tool['name'] ?? null;
                if (! is_string($remoteName) || $remoteName === '') {
                    continue;
                }
                $seen[] = $remoteName;
                $defaults = $this->policy->defaults($tool);
                $model = McpConnectionTool::query()->firstOrNew([
                    'tenant_id' => $connection->tenant_id,
                    'mcp_connector_connection_id' => $connection->getKey(),
                    'remote_name' => $remoteName,
                ]);
                $isNew = ! $model->exists;
                $riskIncreased = ! $isNew && $this->policy->isRiskIncrease((string) $model->risk, $defaults['risk']);
                $model->fill([
                    'local_name' => $model->local_name ?: $this->names->make($connection, $remoteName),
                    'title' => is_string($tool['title'] ?? null) ? $tool['title'] : null,
                    'description' => is_string($tool['description'] ?? null) ? $tool['description'] : null,
                    'input_schema_json' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : ['type' => 'object'],
                    'output_schema_json' => is_array($tool['outputSchema'] ?? null) ? $tool['outputSchema'] : null,
                    'annotations_json' => is_array($tool['annotations'] ?? null) ? $tool['annotations'] : null,
                    'meta_json' => is_array($tool['_meta'] ?? null) ? $tool['_meta'] : null,
                    'risk' => $defaults['risk'],
                    'read_only' => $defaults['risk'] === 'read',
                    'destructive' => $defaults['risk'] === 'destructive',
                    'idempotent' => ($tool['annotations']['idempotentHint'] ?? null) === true,
                    'discovered_at' => now(),
                    'last_seen_at' => now(),
                    'removed_at' => null,
                ]);
                if ($isNew) {
                    $model->policy = 'auto';
                    $model->enabled = $defaults['enabled'];
                    $model->confirmation_required = $defaults['confirmation_required'];
                } elseif ($riskIncreased) {
                    $model->policy = 'disabled';
                    $model->enabled = false;
                    $model->confirmation_required = true;
                } elseif ($model->policy === 'auto') {
                    $model->enabled = $defaults['enabled'];
                    $model->confirmation_required = $defaults['confirmation_required'];
                }
                $model->save();
                $models[] = $model;
            }

            McpConnectionTool::query()
                ->where('tenant_id', $connection->tenant_id)
                ->where('mcp_connector_connection_id', $connection->getKey())
                ->when($seen !== [], fn ($query) => $query->whereNotIn('remote_name', $seen))
                ->update(['enabled' => false, 'removed_at' => now()]);

            return $models;
        });
    }

    private function markConnectionError(McpConnection $connection, string $phase, \Throwable $e, ?McpClient $client = null): void
    {
        $error = ['phase' => $phase, 'class' => $e::class, 'message' => $e->getMessage()];
        $transport = $client?->transport();
        if ($transport instanceof McpProtocolAwareTransportContract) {
            $status = $transport->lastStatusCode();
            $challenge = $this->responseHeader($transport->lastResponseHeaders(), 'www-authenticate');
            if ($status !== null) {
                $error['http_status'] = $status;
            }
            if (in_array($status, [401, 403], true) && $challenge !== null) {
                $error['authorization_required'] = true;
                $connection->server->forceFill(['oauth_metadata_json' => [
                    'www_authenticate' => mb_substr($challenge, 0, 4096),
                    'challenged_at' => now()->toIso8601String(),
                ]])->save();
            }
        }
        $connection->forceFill(['status' => McpConnection::STATUS_ERRORED, 'error_json' => $error])->save();
        $connection->server->forceFill(['status' => McpServerDefinition::STATUS_ERRORED, 'error_json' => $error])->save();
    }

    /** @param array<string,list<string>> $headers */
    private function responseHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $values) {
            if (strcasecmp($header, $name) === 0 && is_string($values[0] ?? null)) {
                return $values[0];
            }
        }

        return null;
    }

    private function assertTenant(McpConnection $connection): void
    {
        if ((string) $connection->tenant_id !== $this->tenantContext->current()) {
            throw new AuthorizationException('MCP connection is outside the active tenant.');
        }
    }
}
