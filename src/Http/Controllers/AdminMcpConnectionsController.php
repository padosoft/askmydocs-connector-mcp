<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\Concerns\ResolvesActor;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionResource;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionManager;
use Padosoft\AskMyDocsConnectorMcp\Services\McpDiscoveryService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpResourceCatalogService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolGovernanceService;

final class AdminMcpConnectionsController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly McpConnectionManager $connections,
        private readonly McpDiscoveryService $discovery,
        private readonly McpToolGovernanceService $governance,
        private readonly McpResourceCatalogService $resources,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json(McpConnection::query()
            ->with(['server', 'tools', 'resources', 'installation'])
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mode', 'shared')
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'endpoint' => ['required', 'string', 'max:2048'],
            'transport' => ['nullable', 'string'],
            'project_key' => ['nullable', 'string', 'max:100'],
            'bearer' => ['nullable', 'string', 'max:8192'],
        ]);
        $connection = $this->connections->createShared($data, (string) $this->actor($request)->getKey());

        return $this->connectCreated($connection);
    }

    public function update(Request $request, string $connection): JsonResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'label' => ['sometimes', 'string', 'max:100'],
            'endpoint' => ['sometimes', 'string', 'max:2048'],
            'transport' => ['sometimes', 'in:auto,streamable_http,legacy_sse,stdio_imported'],
            'project_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bearer' => ['sometimes', 'nullable', 'string', 'max:8192'],
        ]);

        return response()->json($this->connections->update($this->shared($connection), $data));
    }

    public function discover(string $connection): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json($this->discovery->discover($this->shared($connection)));
    }

    public function disconnect(string $connection): JsonResponse
    {
        $this->authorizeAdmin();
        $this->connections->disconnect($this->shared($connection));

        return response()->json(['status' => 'disconnected']);
    }

    public function destroy(string $connection): JsonResponse
    {
        $this->authorizeAdmin();
        $this->connections->delete($this->shared($connection));

        return response()->json(null, 204);
    }

    public function setTool(Request $request, string $connection, int $tool): JsonResponse
    {
        $this->authorizeAdmin();
        $connectionModel = $this->shared($connection);
        $toolModel = McpConnectionTool::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mcp_connector_connection_id', $connectionModel->getKey())
            ->findOrFail($tool);

        return response()->json($this->governance->setEnabled($toolModel, $request->boolean('enabled')));
    }

    public function setResource(Request $request, string $connection, int $resource): JsonResponse
    {
        $this->authorizeAdmin();
        $connectionModel = $this->shared($connection);
        $resourceModel = McpConnectionResource::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mcp_connector_connection_id', $connectionModel->getKey())
            ->findOrFail($resource);

        return response()->json($this->resources->setEnabled($resourceModel, $request->boolean('enabled')));
    }

    public function syncResources(string $connection): JsonResponse
    {
        $this->authorizeAdmin();
        $model = $this->shared($connection);
        if ($model->connector_installation_id === null) {
            return response()->json(['message' => 'MCP resource ingest is not bound to the scheduler.'], 409);
        }
        ConnectorSyncJob::dispatch($model->connector_installation_id, (string) $model->tenant_id);

        return response()->json(['status' => 'queued'], 202);
    }

    private function shared(string $publicId): McpConnection
    {
        return McpConnection::query()
            ->with('server')
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mode', 'shared')
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function authorizeAdmin(): void
    {
        $ability = config('connector-mcp.routes.admin_ability');
        if (is_string($ability) && $ability !== '') {
            Gate::authorize($ability);
        }
    }

    private function connectCreated(McpConnection $connection): JsonResponse
    {
        try {
            return response()->json($this->discovery->discover($connection), 201);
        } catch (\Throwable) {
            $connection->refresh()->load(['server', 'tools', 'resources']);

            return response()->json([
                'connection' => $connection,
                'tools' => [],
                'resources' => [],
                'catalog_error' => null,
                'resource_catalog_error' => null,
                'authorization_required' => data_get($connection->error_json, 'authorization_required') === true,
            ], 201);
        }
    }
}
