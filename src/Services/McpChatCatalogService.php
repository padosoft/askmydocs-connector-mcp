<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Database\Eloquent\Model;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;

final readonly class McpChatCatalogService
{
    public function __construct(private TenantContext $tenantContext) {}

    /** @return list<array<string,mixed>> */
    public function forActor(Model $actor, ?string $projectKey = null): array
    {
        $query = McpConnectionTool::query();
        $query->whereHas('connection', function ($query) use ($actor, $projectKey): void {
            $query->where('status', 'active')
                ->where(function ($scope) use ($actor): void {
                    $scope->where('mode', 'shared')
                        ->orWhere(function ($personal) use ($actor): void {
                            $personal->where('mode', 'personal')
                                ->where('owner_type', $actor->getMorphClass())
                                ->where('owner_id', (string) $actor->getKey());
                        });
                })
                ->where(function ($projects) use ($projectKey): void {
                    $projects->whereNull('project_key');
                    if ($projectKey !== null) {
                        $projects->orWhere('project_key', $projectKey);
                    }
                });
        });
        $toolIds = $query
            ->where('mcp_connector_tools.tenant_id', $this->tenantContext->current())
            ->where('enabled', true)
            ->whereNull('removed_at')
            ->pluck('id')
            ->all();
        $tools = McpConnectionTool::query()->with(['connection.server'])->findMany($toolIds);

        return array_values($tools->map(static fn (McpConnectionTool $tool): array => [
            'name' => $tool->local_name,
            'description' => $tool->description,
            'inputSchema' => $tool->input_schema_json,
            'outputSchema' => $tool->output_schema_json,
            'annotations' => $tool->annotations_json,
            '_meta' => $tool->meta_json,
            'risk' => $tool->risk,
            'confirmationRequired' => (bool) $tool->confirmation_required,
            'source' => 'mcp',
            'provenance' => [
                'server_id' => $tool->connection->server->getKey(),
                'server_name' => $tool->connection->server->name,
                'connection_id' => $tool->connection->public_id,
                'tool_remote_name' => $tool->remote_name,
                'tool_local_name' => $tool->local_name,
            ],
        ])->all());
    }
}
