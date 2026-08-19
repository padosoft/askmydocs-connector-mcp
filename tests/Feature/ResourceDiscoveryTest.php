<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionResource;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpDiscoveryService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpResourceCatalogService;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\ScriptedTransport;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final class ResourceDiscoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_discovery_reconciles_resources_without_enabling_them(): void
    {
        $transport = new ScriptedTransport;
        $transport->responses['server/discover'] = [
            'protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION,
            'capabilities' => ['tools' => [], 'resources' => []],
        ];
        $transport->responses['tools/list'] = ['tools' => []];
        $transport->responses['resources/list'] = ['resources' => [[
            'uri' => 'docs://handbook',
            'name' => 'Handbook',
            'mimeType' => 'text/markdown',
            'size' => 120,
            '_meta' => ['revision' => '1'],
        ]]];
        McpClient::useTransportResolver(static fn () => $transport);

        $connection = $this->connection();
        $result = app(McpDiscoveryService::class)->discover($connection);

        $this->assertNull($result['resource_catalog_error']);
        $this->assertCount(1, $result['resources']);
        $resource = $result['resources'][0];
        $this->assertSame('docs://handbook', $resource->uri);
        $this->assertSame('text/markdown', $resource->mime_type);
        $this->assertFalse($resource->enabled);

        $transport->responses['resources/list'] = ['resources' => []];
        app(McpDiscoveryService::class)->discover($connection->refresh());
        $this->assertNotNull($resource->refresh()->removed_at);
    }

    public function test_only_shared_resources_can_be_enabled_for_ingest(): void
    {
        $shared = $this->connection();
        $resource = McpConnectionResource::query()->create([
            'mcp_connector_connection_id' => $shared->getKey(),
            'uri' => 'docs://shared',
            'uri_hash' => hash('sha256', 'docs://shared'),
        ]);
        $this->assertTrue(app(McpResourceCatalogService::class)->setEnabled($resource, true)->enabled);

        $personal = $this->connection('personal');
        $personalResource = McpConnectionResource::query()->create([
            'mcp_connector_connection_id' => $personal->getKey(),
            'uri' => 'docs://personal',
            'uri_hash' => hash('sha256', 'docs://personal'),
        ]);

        $this->expectException(AuthorizationException::class);
        app(McpResourceCatalogService::class)->setEnabled($personalResource, true);
    }

    private function connection(string $mode = 'shared'): McpConnection
    {
        app(TenantContext::class)->set('acme');
        $server = McpServerDefinition::query()->create([
            'name' => 'Resource MCP',
            'transport' => 'auto',
            'endpoint' => 'https://resources.example.test/mcp/'.uniqid(),
        ]);

        return McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => $mode,
            'owner_type' => $mode === 'personal' ? 'user' : null,
            'owner_id' => $mode === 'personal' ? '42' : null,
            'label' => 'Resources',
            'status' => McpConnection::STATUS_PENDING,
        ]);
    }
}
