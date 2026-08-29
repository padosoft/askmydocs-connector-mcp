<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Exceptions\PersonalMcpConnectionLimitExceeded;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionManager;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\ScriptedTransport;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final class PersonalConnectionAbuseProtectionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('connector-mcp.enabled', true);
        $app['config']->set('connector-mcp.routes.middleware', ['web']);
        $app['config']->set('connector-mcp.personal_connections.discovery_requests_per_minute', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('acme');
        $transport = new ScriptedTransport;
        $transport->responses['server/discover'] = [
            'protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION,
            'capabilities' => ['tools' => []],
        ];
        $transport->responses['tools/list'] = ['tools' => []];
        McpClient::useTransportResolver(static fn () => $transport);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_personal_connection_creation_is_rate_limited(): void
    {
        $user = TestUser::query()->create(['name' => 'Marco']);
        $server = $this->approvedServer();

        $this->actingAs($user)->postJson('/api/me/connected-apps/mcp', [
            'server_id' => $server->getKey(),
        ])->assertCreated();
        $this->actingAs($user)->postJson('/api/me/connected-apps/mcp', [
            'server_id' => $server->getKey(),
        ])->assertTooManyRequests();
    }

    public function test_personal_connection_count_is_bounded_per_owner_and_tenant(): void
    {
        config()->set('connector-mcp.personal_connections.max_per_owner', 1);
        $user = TestUser::query()->create(['name' => 'Marco']);
        $server = $this->approvedServer();
        McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'personal',
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'label' => 'Existing',
        ]);

        $this->expectException(PersonalMcpConnectionLimitExceeded::class);
        app(McpConnectionManager::class)->createPersonal([
            'server_id' => $server->getKey(),
        ], $user);
    }

    private function approvedServer(): McpServerDefinition
    {
        return McpServerDefinition::query()->create([
            'name' => 'Approved MCP',
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://8.8.8.8/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
    }
}
