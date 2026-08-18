<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Support\Facades\URL;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class HttpApiIsolationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.url', 'https://askmydocs.example');
        $app['config']->set('connector-mcp.enabled', true);
        $app['config']->set('connector-mcp.routes.middleware', ['web']);
        $app['config']->set('connector-mcp.routes.admin_ability', null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('https://askmydocs.example');
        URL::forceScheme('https');
    }

    public function test_personal_and_admin_catalogs_expose_only_their_connection_modes(): void
    {
        app(TenantContext::class)->set('acme');
        $marco = TestUser::query()->create(['name' => 'Marco']);
        $other = TestUser::query()->create(['name' => 'Other']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Approved',
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://mcp.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $shared = $this->connection($server, 'shared', null, 'Shared');
        $mine = $this->connection($server, 'personal', $marco, 'Mine');
        $this->connection($server, 'personal', $other, 'Hidden');

        $personal = $this->actingAs($marco)->getJson('/api/me/connected-apps/mcp');
        $personal->assertOk()->assertJsonCount(1)->assertJsonPath('0.public_id', $mine->public_id);

        $admin = $this->actingAs($marco)->getJson('/api/admin/connectors/mcp');
        $admin->assertOk()->assertJsonCount(1)->assertJsonPath('0.public_id', $shared->public_id);
    }

    public function test_metadata_document_is_public_but_fail_closed_with_the_package_flag(): void
    {
        $this->getJson('/.well-known/mcp-client.json')
            ->assertOk()
            ->assertJsonPath('client_id', 'https://askmydocs.example/.well-known/mcp-client.json')
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');

        config()->set('connector-mcp.enabled', false);
        $this->getJson('/.well-known/mcp-client.json')->assertNotFound();
    }

    private function connection(McpServerDefinition $server, string $mode, ?TestUser $owner, string $label): McpConnection
    {
        return McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => $mode,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'label' => $label,
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
    }
}
