<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionResource;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final readonly class McpResourceCatalogService
{
    public function __construct(private TenantContext $tenantContext) {}

    /** @return list<McpConnectionResource> */
    public function discover(McpConnection $connection, McpClient $client): array
    {
        $this->assertTenant($connection);

        return $this->reconcile($connection, $this->drain($client));
    }

    /** @return list<McpConnectionResource> */
    public function markUnsupported(McpConnection $connection): array
    {
        $this->assertTenant($connection);

        return $this->reconcile($connection, []);
    }

    public function setEnabled(McpConnectionResource $resource, bool $enabled): McpConnectionResource
    {
        $resource->loadMissing('connection');
        $this->assertTenant($resource->connection);
        if ($resource->connection->isPersonal()) {
            throw new AuthorizationException('Personal MCP resources cannot feed the shared knowledge base.');
        }

        $resource->forceFill([
            'enabled' => $enabled,
            'ingest_error_json' => null,
        ])->save();

        return $resource->refresh();
    }

    /** @return list<array<string,mixed>> */
    private function drain(McpClient $client): array
    {
        $all = [];
        $cursor = null;
        $maxPages = max(1, (int) config('connector-mcp.http.max_catalog_pages', 20));
        for ($pageNumber = 0; $pageNumber < $maxPages; $pageNumber++) {
            $page = $client->listResourcesPage($cursor);
            array_push($all, ...$page->items);
            $cursor = $page->nextCursor;
            if ($cursor === null) {
                return $all;
            }
        }

        throw new \RuntimeException('MCP resource catalog exceeded the configured page limit.');
    }

    /**
     * @param  list<array<string,mixed>>  $remoteResources
     * @return list<McpConnectionResource>
     */
    private function reconcile(McpConnection $connection, array $remoteResources): array
    {
        return DB::transaction(function () use ($connection, $remoteResources): array {
            $seen = [];
            $models = [];
            foreach ($remoteResources as $resource) {
                $uri = $resource['uri'] ?? null;
                if (! is_string($uri) || trim($uri) === '') {
                    continue;
                }
                $hash = hash('sha256', $uri);
                $seen[] = $hash;
                $model = McpConnectionResource::query()->firstOrNew([
                    'tenant_id' => $connection->tenant_id,
                    'mcp_connector_connection_id' => $connection->getKey(),
                    'uri_hash' => $hash,
                ]);
                if ($model->exists && ! hash_equals((string) $model->uri, $uri)) {
                    throw new \RuntimeException('MCP resource URI hash collision detected.');
                }
                $model->fill([
                    'uri' => $uri,
                    'name' => is_string($resource['name'] ?? null) ? $resource['name'] : null,
                    'title' => is_string($resource['title'] ?? null) ? $resource['title'] : null,
                    'description' => is_string($resource['description'] ?? null) ? $resource['description'] : null,
                    'mime_type' => is_string($resource['mimeType'] ?? null) ? $resource['mimeType'] : null,
                    'size' => is_int($resource['size'] ?? null) && $resource['size'] >= 0 ? $resource['size'] : null,
                    'annotations_json' => is_array($resource['annotations'] ?? null) ? $resource['annotations'] : null,
                    'meta_json' => is_array($resource['_meta'] ?? null) ? $resource['_meta'] : null,
                    'discovered_at' => $model->discovered_at ?? now(),
                    'last_seen_at' => now(),
                    'removed_at' => null,
                ]);
                if (! $model->exists) {
                    $model->enabled = false;
                }
                $model->save();
                $models[] = $model;
            }

            McpConnectionResource::query()
                ->where('tenant_id', $connection->tenant_id)
                ->where('mcp_connector_connection_id', $connection->getKey())
                ->when($seen !== [], fn ($query) => $query->whereNotIn('uri_hash', $seen))
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);

            return $models;
        });
    }

    private function assertTenant(McpConnection $connection): void
    {
        if ((string) $connection->tenant_id !== $this->tenantContext->current()) {
            throw new AuthorizationException('MCP connection is outside the active tenant.');
        }
    }
}
