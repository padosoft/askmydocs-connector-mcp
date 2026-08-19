<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionResource;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpResourceIngestionService;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\ScriptedTransport;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final class ResourceIngestionTest extends TestCase
{
    private SpyIngestionContract $ingestion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        $this->ingestion = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->ingestion);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_enabled_resources_are_ingested_once_and_changed_content_is_updated(): void
    {
        [$installation, $resource] = $this->fixture();
        $transport = $this->transport('# Handbook');
        McpClient::useTransportResolver(static fn () => $transport);

        $first = app(McpResourceIngestionService::class)->sync($installation);
        $this->assertSame(1, $first->documentsAdded);
        $this->assertSame(0, $first->documentsUpdated);
        $this->assertCount(1, $this->ingestion->dispatches);
        $dispatch = $this->ingestion->dispatches[0];
        $this->assertSame('acme-kb', $dispatch['projectKey']);
        $this->assertSame('mcp', $dispatch['metadata']['connector']);
        $this->assertSame('docs://handbook', $dispatch['metadata']['mcp_resource_uri']);
        Storage::disk('kb')->assertExists($dispatch['relativePath']);

        $second = app(McpResourceIngestionService::class)->sync($installation->refresh());
        $this->assertSame(0, $second->totalChanged());
        $this->assertCount(1, $this->ingestion->dispatches);

        $transport->responses['resources/read'] = ['contents' => [[
            'uri' => 'docs://handbook',
            'mimeType' => 'text/markdown',
            'text' => '# Handbook v2',
        ]]];
        $third = app(McpResourceIngestionService::class)->sync($installation->refresh());
        $this->assertSame(1, $third->documentsUpdated);
        $this->assertCount(2, $this->ingestion->dispatches);
        $this->assertCount(1, $this->ingestion->deletions);
        $this->assertNotNull($resource->refresh()->content_hash);
    }

    public function test_disabled_or_removed_resources_are_deleted_from_grounding(): void
    {
        [$installation, $resource] = $this->fixture();
        $resource->forceFill(['enabled' => false, 'content_hash' => hash('sha256', 'old')])->save();
        $transport = $this->transport('# ignored');
        McpClient::useTransportResolver(static fn () => $transport);

        $result = app(McpResourceIngestionService::class)->sync($installation);

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertCount(0, $this->ingestion->dispatches);
        $this->assertNull($resource->refresh()->content_hash);
    }

    public function test_resource_byte_limit_is_reported_as_partial_error(): void
    {
        config()->set('connector-mcp.ingest.max_resource_bytes', 4);
        [$installation, $resource] = $this->fixture();
        $transport = $this->transport('too large');
        McpClient::useTransportResolver(static fn () => $transport);

        $result = app(McpResourceIngestionService::class)->sync($installation);

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('byte limit', $result->errors[0]);
        $this->assertNotNull($resource->refresh()->ingest_error_json);
        $this->assertCount(0, $this->ingestion->dispatches);
    }

    /** @return array{ConnectorInstallation,McpConnectionResource} */
    private function fixture(): array
    {
        app(TenantContext::class)->set('acme');
        $server = McpServerDefinition::query()->create([
            'name' => 'Resource MCP',
            'transport' => 'auto',
            'endpoint' => 'https://resources.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Resources',
            'project_key' => 'acme-kb',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
        $resource = McpConnectionResource::query()->create([
            'mcp_connector_connection_id' => $connection->getKey(),
            'uri' => 'docs://handbook',
            'uri_hash' => hash('sha256', 'docs://handbook'),
            'name' => 'Handbook',
            'mime_type' => 'text/markdown',
            'enabled' => true,
        ]);
        $installation = ConnectorInstallation::query()->create([
            'connector_name' => 'mcp',
            'label' => 'Resources',
            'project_key' => 'acme-kb',
            'config_json' => ['mcp_connection_public_id' => $connection->public_id],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        return [$installation, $resource];
    }

    private function transport(string $text): ScriptedTransport
    {
        $transport = new ScriptedTransport;
        $transport->responses['server/discover'] = [
            'protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION,
            'capabilities' => ['resources' => []],
        ];
        $transport->responses['resources/list'] = ['resources' => [[
            'uri' => 'docs://handbook',
            'name' => 'Handbook',
            'mimeType' => 'text/markdown',
        ]]];
        $transport->responses['resources/read'] = ['contents' => [[
            'uri' => 'docs://handbook',
            'mimeType' => 'text/markdown',
            'text' => $text,
        ]]];

        return $transport;
    }
}
