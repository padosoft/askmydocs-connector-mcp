<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpDiscoveryService;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\ScriptedTransport;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

final class DiscoveryPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_read_tools_auto_enable_and_risk_increase_disables_them(): void
    {
        $transport = new ScriptedTransport;
        $transport->responses['server/discover'] = [
            'protocolVersion' => '2026-07-28',
            'capabilities' => ['tools' => []],
        ];
        $transport->responses['tools/list'] = ['tools' => [[
            'name' => 'records.update',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
        ]]];
        McpClient::useTransportResolver(static fn () => $transport);

        $connection = $this->connection();
        $service = app(McpDiscoveryService::class);
        $first = $service->discover($connection);
        $this->assertTrue($first['tools'][0]->enabled);
        $this->assertSame('read', $first['tools'][0]->risk);

        $transport->responses['tools/list'] = ['tools' => [[
            'name' => 'records.update',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
        ]]];
        $second = $service->discover($connection->refresh());

        $this->assertFalse($second['tools'][0]->enabled);
        $this->assertSame('disabled', $second['tools'][0]->policy);
        $this->assertSame('destructive', $second['tools'][0]->risk);
    }

    public function test_tool_catalog_failure_does_not_deactivate_negotiated_connection(): void
    {
        $transport = new ScriptedTransport;
        $transport->responses['server/discover'] = ['protocolVersion' => '2026-07-28'];
        $transport->responses['tools/list'] = JsonRpcMessage::errorResponse('ignored', -32000, 'catalog temporarily unavailable');
        McpClient::useTransportResolver(static fn () => $transport);

        $result = app(McpDiscoveryService::class)->discover($this->connection());

        $this->assertSame(McpConnection::STATUS_ACTIVE, $result['connection']->status);
        $this->assertStringContainsString('catalog temporarily unavailable', (string) $result['catalog_error']);
        $this->assertSame('tools_list', $result['connection']->error_json['phase']);
    }

    private function connection(): McpConnection
    {
        app(TenantContext::class)->set('acme');
        $server = McpServerDefinition::query()->create([
            'name' => 'Fixture MCP',
            'transport' => 'auto',
            'endpoint' => 'https://fixture.example.test/mcp',
        ]);

        return McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Fixture',
            'status' => McpConnection::STATUS_PENDING,
        ]);
    }
}
