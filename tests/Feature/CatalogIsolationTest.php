<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpChatCatalogService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpPendingInteractionService;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class CatalogIsolationTest extends TestCase
{
    public function test_catalog_combines_project_shared_and_current_users_personal_tools_only(): void
    {
        app(TenantContext::class)->set('acme');
        $marco = TestUser::query()->create(['name' => 'Marco']);
        $other = TestUser::query()->create(['name' => 'Other']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Catalog', 'transport' => 'auto', 'endpoint' => 'https://catalog.example.test/mcp',
        ]);
        $shared = $this->connection($server, 'shared', null, 'sales');
        $marcoPersonal = $this->connection($server, 'personal', $marco, 'sales');
        $otherPersonal = $this->connection($server, 'personal', $other, 'sales');
        $wrongProject = $this->connection($server, 'shared', null, 'support');
        $this->tool($shared, 'shared_search');
        $this->tool($marcoPersonal, 'my_search');
        $this->tool($otherPersonal, 'other_search');
        $this->tool($wrongProject, 'support_search');

        $names = array_column(app(McpChatCatalogService::class)->forActor($marco, 'sales'), 'name');

        sort($names);
        $this->assertSame(['my_search', 'shared_search'], $names);
    }

    public function test_pending_interaction_is_single_use_and_actor_conversation_scoped(): void
    {
        app(TenantContext::class)->set('acme');
        $marco = TestUser::query()->create(['name' => 'Marco']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Pending', 'transport' => 'auto', 'endpoint' => 'https://pending.example.test/mcp',
        ]);
        $connection = $this->connection($server, 'personal', $marco, null);
        $service = app(McpPendingInteractionService::class);
        $interaction = $service->create($connection, $marco, 'conversation-1', 'confirmation', ['tool' => 'x'], ['message' => 'Confirm']);

        $service->consume($interaction->public_id, $marco, 'conversation-1');

        $this->expectException(\RuntimeException::class);
        $service->consume($interaction->public_id, $marco, 'conversation-1');
    }

    public function test_catalog_exposes_only_sanitized_optional_agent_capability_hints(): void
    {
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Planner']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Orders', 'transport' => 'auto', 'endpoint' => 'https://orders.example.test/mcp',
        ]);
        $connection = $this->connection($server, 'personal', $actor, 'sales');
        $this->tool($connection, 'list_orders', [
            'askmydocs/agent-capability' => [
                'entity' => 'orders',
                'operation' => 'list',
                'collection_path' => 'items',
                'authorization' => 'bypass',
            ],
            'io.modelcontextprotocol/ui' => ['resourceUri' => 'ui://orders/list'],
        ]);

        $catalog = app(McpChatCatalogService::class)->forActor($actor, 'sales');

        $this->assertSame('orders', data_get($catalog, '0.agentCapability.entity'));
        $this->assertSame('items', data_get($catalog, '0.agentCapability.collection_path'));
        $this->assertArrayNotHasKey('authorization', $catalog[0]['agentCapability']);
        $this->assertArrayNotHasKey('askmydocs/agent-capability', $catalog[0]['_meta']);
        $this->assertSame('ui://orders/list', $catalog[0]['_meta']['io.modelcontextprotocol/ui']['resourceUri']);
    }

    private function connection(McpServerDefinition $server, string $mode, ?TestUser $owner, ?string $project): McpConnection
    {
        return McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => $mode,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'label' => $mode,
            'project_key' => $project,
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string,mixed>|null $meta */
    private function tool(McpConnection $connection, string $name, ?array $meta = null): void
    {
        McpConnectionTool::query()->create([
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => $name,
            'local_name' => $name,
            'input_schema_json' => ['type' => 'object'],
            'meta_json' => $meta,
            'risk' => 'read',
            'policy' => 'auto',
            'enabled' => true,
            'confirmation_required' => false,
        ]);
    }
}
