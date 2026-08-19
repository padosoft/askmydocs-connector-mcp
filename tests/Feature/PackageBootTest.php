<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class PackageBootTest extends TestCase
{
    public function test_provider_loads_default_off_config_and_all_package_tables(): void
    {
        $this->assertFalse(config('connector-mcp.enabled'));

        foreach ([
            'mcp_connector_servers',
            'mcp_connector_connections',
            'mcp_connector_credentials',
            'mcp_connector_tools',
            'mcp_connector_oauth_clients',
            'mcp_connector_oauth_attempts',
            'mcp_connector_pending_interactions',
            'mcp_connector_resources',
            'mcp_connector_remote_tasks',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }
    }

    public function test_models_auto_fill_tenant_and_default_tools_to_safe_policy(): void
    {
        app(TenantContext::class)->set('acme');

        $server = McpServerDefinition::query()->create([
            'name' => 'CRM MCP',
            'transport' => 'http',
            'endpoint' => 'https://mcp.example.test/mcp',
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->id,
            'owner_type' => 'App\\Models\\User',
            'owner_id' => '42',
            'label' => 'Marco',
            'project_key' => 'sales',
        ]);
        $tool = McpConnectionTool::query()->create([
            'mcp_connector_connection_id' => $connection->id,
            'remote_name' => 'search_contacts',
            'local_name' => 'crm_search_contacts',
            'input_schema_json' => ['type' => 'object', 'properties' => []],
        ])->refresh();

        $this->assertSame('acme', $server->tenant_id);
        $this->assertSame('acme', $connection->tenant_id);
        $this->assertSame('acme', $tool->tenant_id);
        $this->assertFalse($tool->enabled);
        $this->assertTrue($tool->confirmation_required);
    }
}
