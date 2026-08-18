<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\Concerns\ResolvesActor;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionManager;
use Padosoft\AskMyDocsConnectorMcp\Services\McpDiscoveryService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolGovernanceService;

final class PersonalMcpConnectionsController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly McpConnectionManager $connections,
        private readonly McpDiscoveryService $discovery,
        private readonly McpToolGovernanceService $governance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(McpConnection::query()->with(['server', 'tools'])
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mode', 'personal')
            ->where('owner_type', $actor->getMorphClass())
            ->where('owner_id', (string) $actor->getKey())
            ->get());
    }

    public function catalog(): JsonResponse
    {
        return response()->json(McpServerDefinition::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('catalog_scope', 'tenant')
            ->whereIn('auth_mode', ['none', 'oauth'])
            ->whereNotIn('transport', ['stdio_imported'])
            ->whereNull('legacy_headers_encrypted')
            ->orderBy('name')
            ->get(['id', 'name', 'endpoint', 'transport', 'negotiated_era', 'negotiated_version', 'status']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['nullable', 'integer'],
            'name' => ['required_without:server_id', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'endpoint' => ['required_without:server_id', 'string', 'max:2048'],
            'transport' => ['nullable', 'in:auto,streamable_http,legacy_sse'],
            'project_key' => ['nullable', 'string', 'max:100'],
            'bearer' => ['nullable', 'string', 'max:8192'],
        ]);
        $connection = $this->connections->createPersonal($data, $this->actor($request));

        return $this->connectCreated($connection);
    }

    public function update(Request $request, string $connection): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'label' => ['sometimes', 'string', 'max:100'],
            'endpoint' => ['sometimes', 'string', 'max:2048'],
            'transport' => ['sometimes', 'in:auto,streamable_http,legacy_sse'],
            'project_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bearer' => ['sometimes', 'nullable', 'string', 'max:8192'],
        ]);

        return response()->json($this->connections->update($this->owned($request, $connection), $data));
    }

    public function discover(Request $request, string $connection): JsonResponse
    {
        return response()->json($this->discovery->discover($this->owned($request, $connection)));
    }

    public function disconnect(Request $request, string $connection): JsonResponse
    {
        $this->connections->disconnect($this->owned($request, $connection));

        return response()->json(['status' => 'disconnected']);
    }

    public function destroy(Request $request, string $connection): JsonResponse
    {
        $this->connections->delete($this->owned($request, $connection));

        return response()->json(null, 204);
    }

    public function setTool(Request $request, string $connection, int $tool): JsonResponse
    {
        $connectionModel = $this->owned($request, $connection);
        $toolModel = McpConnectionTool::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mcp_connector_connection_id', $connectionModel->getKey())
            ->findOrFail($tool);

        return response()->json($this->governance->setEnabled($toolModel, $request->boolean('enabled')));
    }

    private function owned(Request $request, string $publicId): McpConnection
    {
        $connection = McpConnection::query()->with('server')
            ->where('tenant_id', $this->tenantContext->current())
            ->where('public_id', $publicId)
            ->firstOrFail();
        $this->connections->assertOwner($connection, $this->actor($request));

        return $connection;
    }

    private function connectCreated(McpConnection $connection): JsonResponse
    {
        try {
            return response()->json($this->discovery->discover($connection), 201);
        } catch (\Throwable) {
            $connection->refresh()->load(['server', 'tools']);

            return response()->json([
                'connection' => $connection,
                'tools' => [],
                'catalog_error' => null,
                'authorization_required' => data_get($connection->error_json, 'authorization_required') === true,
            ], 201);
        }
    }
}
