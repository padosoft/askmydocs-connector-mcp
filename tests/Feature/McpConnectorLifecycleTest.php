<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\McpConnector;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionManager;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class McpConnectorLifecycleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('connector-mcp.http.internal_endpoint_allowlist', ['mcp.example.test']);
    }

    public function test_shared_connection_projects_to_connector_installation_lifecycle(): void
    {
        app(TenantContext::class)->set('acme');
        $manager = app(McpConnectionManager::class);
        $connection = $manager->createShared([
            'name' => 'Knowledge MCP',
            'label' => 'Knowledge',
            'endpoint' => 'https://mcp.example.test/rpc',
            'project_key' => 'acme-kb',
        ], '42');

        $installation = ConnectorInstallation::query()->findOrFail($connection->connector_installation_id);
        $this->assertSame(McpConnector::KEY, $installation->connector_name);
        $this->assertSame('Knowledge', $installation->label);
        $this->assertSame('acme-kb', $installation->project_key);
        $this->assertSame($connection->public_id, $installation->config_json['mcp_connection_public_id']);

        $manager->disconnect($connection->refresh());
        $this->assertSame(ConnectorInstallation::STATUS_DISABLED, $installation->refresh()->status);

        $manager->delete($connection->refresh()->load('server'));
        $this->assertDatabaseMissing('connector_installations', ['id' => $installation->getKey()]);
    }

    public function test_personal_connection_never_creates_ingest_installation(): void
    {
        app(TenantContext::class)->set('acme');
        $owner = TestUser::query()->create(['name' => 'Marco']);
        $connection = app(McpConnectionManager::class)->createPersonal([
            'name' => 'Private MCP',
            'endpoint' => 'https://mcp.example.test/private',
        ], $owner);

        $this->assertNull($connection->connector_installation_id);
        $this->assertDatabaseCount('connector_installations', 0);
    }

    public function test_duplicate_shared_labels_get_stable_unique_installation_labels(): void
    {
        app(TenantContext::class)->set('acme');
        $manager = app(McpConnectionManager::class);
        $first = $manager->createShared([
            'name' => 'One', 'label' => 'Knowledge', 'endpoint' => 'https://mcp.example.test/one',
        ], '42');
        $second = $manager->createShared([
            'name' => 'Two', 'label' => 'Knowledge', 'endpoint' => 'https://mcp.example.test/two',
        ], '42');

        $labels = ConnectorInstallation::query()->orderBy('id')->pluck('label')->all();
        $this->assertSame('Knowledge', $labels[0]);
        $this->assertSame('Knowledge-'.strtolower(substr($second->public_id, -6)), $labels[1]);
        $this->assertNotSame($first->connector_installation_id, $second->connector_installation_id);
    }

    public function test_existing_shared_connection_can_be_projected_idempotently(): void
    {
        app(TenantContext::class)->set('acme');
        $server = McpServerDefinition::query()->create([
            'name' => 'Imported',
            'endpoint' => 'https://mcp.example.test/imported',
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Imported',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);

        $manager = app(McpConnectionManager::class);
        $first = $manager->ensureIngestInstallation($connection, '42');
        $second = $manager->ensureIngestInstallation($connection->refresh(), '42');

        $this->assertNotNull($first);
        $this->assertSame($first->getKey(), $second?->getKey());
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $first->status);
        $this->assertDatabaseCount('connector_installations', 1);
    }
}
