<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpCredentialVault;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class McpCredentialVaultTest extends TestCase
{
    public function test_tokens_are_encrypted_at_rest_and_read_through_the_vault(): void
    {
        $connection = $this->connection();
        $vault = app(McpCredentialVault::class);

        $credential = $vault->put(
            connection: $connection,
            accessToken: 'access-secret',
            refreshToken: 'refresh-secret',
            expiresAt: now()->addHour(),
            scopes: ['contacts:read'],
            issuer: 'https://auth.example.test',
            resource: 'https://mcp.example.test/mcp',
        );

        $raw = DB::table('mcp_connector_credentials')->where('id', $credential->id)->first();

        $this->assertNotSame('access-secret', $raw->access_token);
        $this->assertNotSame('refresh-secret', $raw->refresh_token);
        $this->assertSame('access-secret', $vault->accessToken($connection));
        $this->assertSame('refresh-secret', $vault->refreshToken($connection));
        $this->assertArrayNotHasKey('access_token', $credential->toArray());
        $this->assertArrayNotHasKey('refresh_token', $credential->toArray());
    }

    public function test_expired_access_token_is_not_returned_but_refresh_token_remains_available(): void
    {
        $connection = $this->connection();
        $vault = app(McpCredentialVault::class);

        $vault->put(
            connection: $connection,
            accessToken: 'expired-access',
            refreshToken: 'usable-refresh',
            expiresAt: now()->subMinute(),
        );

        $this->assertNull($vault->accessToken($connection));
        $this->assertSame('usable-refresh', $vault->refreshToken($connection));
    }

    public function test_vault_rejects_a_connection_from_another_active_tenant(): void
    {
        $connection = $this->connection();
        app(TenantContext::class)->set('other');

        $this->expectException(\InvalidArgumentException::class);

        app(McpCredentialVault::class)->accessToken($connection);
    }

    private function connection(): McpConnection
    {
        app(TenantContext::class)->set('acme');

        $server = McpServerDefinition::query()->create([
            'name' => 'CRM MCP',
            'transport' => 'http',
            'endpoint' => 'https://mcp.example.test/mcp',
        ]);

        return McpConnection::query()->create([
            'mcp_connector_server_id' => $server->id,
            'owner_type' => 'App\\Models\\User',
            'owner_id' => '42',
            'label' => 'Marco',
        ]);
    }
}
