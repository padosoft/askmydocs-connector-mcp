<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Events\McpToolInvocationFinished;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolCatalogFingerprint;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolExecutor;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

final class ToolInteractionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('connector-mcp.enabled', true);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_write_confirmation_and_mrtr_resume_the_original_tool_call(): void
    {
        Event::fake([McpToolInvocationFinished::class]);
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Interactive MCP',
            'transport' => 'auto',
            'endpoint' => 'https://interactive.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Interactive',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
        $writeTool = $this->tool($connection, 'records.update', 'mcp_interactive_records_update', true);
        $readTool = $this->tool($connection, 'records.lookup', 'mcp_interactive_records_lookup', false);

        $transport = new InteractionTransport([
            ['content' => [['type' => 'text', 'text' => 'Write completed.']]],
            [
                'resultType' => 'input_required',
                'requestState' => 'request-state-1',
                'inputRequests' => [['name' => 'record_id', 'required' => true]],
                'content' => [['type' => 'text', 'text' => 'Choose a record.']],
            ],
            ['content' => [['type' => 'text', 'text' => 'Fresh MCP evidence.']]],
        ]);
        McpClient::useTransportResolver(static fn () => $transport);
        $executor = app(McpToolExecutor::class);

        $confirmation = $executor->invoke($writeTool->local_name, ['value' => 'new'], $actor, 'conversation-1');
        $this->assertSame('confirmation_required', $confirmation->status);
        $this->assertNotNull($confirmation->pendingInteractionId);
        $this->assertSame([], $transport->toolCalls());

        $confirmed = $executor->resume(
            (string) $confirmation->pendingInteractionId,
            ['confirmed' => true],
            $actor,
            'conversation-1',
        );
        $this->assertSame('completed', $confirmed->status);
        $this->assertStringContainsString('Write completed.', (string) $confirmed->artifact?->llmText);

        $inputRequired = $executor->invoke($readTool->local_name, ['query' => 'latest'], $actor, 'conversation-1');
        $this->assertSame('input_required', $inputRequired->status);
        $this->assertNotNull($inputRequired->pendingInteractionId);

        $resumed = $executor->resume(
            (string) $inputRequired->pendingInteractionId,
            ['record_id' => 'record-42'],
            $actor,
            'conversation-1',
        );
        $this->assertSame('completed', $resumed->status);
        $this->assertStringContainsString('Fresh MCP evidence.', (string) $resumed->artifact?->llmText);

        $calls = $transport->toolCalls();
        $this->assertCount(3, $calls);
        $this->assertSame('request-state-1', $calls[2]->params['requestState'] ?? null);
        $this->assertSame(['record_id' => 'record-42'], $calls[2]->params['inputResponses'] ?? null);
        $this->assertSame(['query' => 'latest'], $calls[2]->params['arguments'] ?? null);
        Event::assertDispatchedTimes(McpToolInvocationFinished::class, 3);
        Event::assertDispatched(
            McpToolInvocationFinished::class,
            static fn (McpToolInvocationFinished $event): bool => $event->outcome?->status === 'completed'
                && $event->provenance['tool_remote_name'] === 'records.update',
        );
    }

    public function test_recent_modern_discovery_is_reused_for_a_single_physical_tool_request(): void
    {
        Event::fake([McpToolInvocationFinished::class]);
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Modern MCP',
            'transport' => 'auto',
            'endpoint' => 'https://modern.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
            'negotiated_era' => 'modern',
            'negotiated_version' => McpClient::MODERN_PROTOCOL_VERSION,
            'capabilities_json' => ['tools' => []],
            'server_info_json' => ['name' => 'modern'],
            'last_discovered_at' => now(),
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Modern',
            'status' => McpConnection::STATUS_ACTIVE,
            'last_discovered_at' => now(),
        ]);
        $tool = $this->tool($connection, 'records.lookup', 'mcp_modern_records_lookup', false);
        $this->markCatalogCurrent($connection);
        $transport = new InteractionTransport([
            ['content' => [['type' => 'text', 'text' => 'Found.']]],
            ['content' => [['type' => 'text', 'text' => 'Found after catalog change.']]],
        ]);
        McpClient::useTransportResolver(static fn () => $transport);

        $outcome = app(McpToolExecutor::class)->invoke(
            $tool->local_name,
            ['query' => 'record-42'],
            $actor,
            'conversation-1',
        );

        $this->assertSame('completed', $outcome->status);
        $this->assertSame(['tools/call'], $transport->methods());
        Event::assertDispatched(
            McpToolInvocationFinished::class,
            static fn (McpToolInvocationFinished $event): bool => $event->provenance['negotiation_cache_hit'] === true
                && $event->provenance['physical_request_count'] === 1
                && $event->provenance['protocol_era'] === 'modern',
        );

        $tool->forceFill(['input_schema_json' => [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
        ]])->save();
        app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-1');
        $this->assertSame(['tools/call', 'server/discover', 'tools/call'], $transport->methods());
        Event::assertDispatched(
            McpToolInvocationFinished::class,
            static fn (McpToolInvocationFinished $event): bool => $event->provenance['negotiation_cache_hit'] === false
                && $event->provenance['physical_request_count'] === 2,
        );
    }

    public function test_stale_negotiation_discovers_once_and_reports_two_physical_requests(): void
    {
        Event::fake([McpToolInvocationFinished::class]);
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Stale MCP',
            'transport' => 'auto',
            'endpoint' => 'https://stale.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
            'negotiated_era' => 'modern',
            'negotiated_version' => McpClient::MODERN_PROTOCOL_VERSION,
            'last_discovered_at' => now()->subHour(),
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Stale',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
        $tool = $this->tool($connection, 'records.lookup', 'mcp_stale_records_lookup', false);
        $transport = new InteractionTransport([
            ['content' => [['type' => 'text', 'text' => 'Found.']]],
        ]);
        McpClient::useTransportResolver(static fn () => $transport);

        app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-1');

        $this->assertSame(['server/discover', 'tools/call'], $transport->methods());
        Event::assertDispatched(
            McpToolInvocationFinished::class,
            static fn (McpToolInvocationFinished $event): bool => $event->provenance['negotiation_cache_hit'] === false
                && $event->provenance['physical_request_count'] === 2,
        );
    }

    public function test_read_only_call_renegotiates_only_once_after_method_rejection(): void
    {
        Event::fake([McpToolInvocationFinished::class]);
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Changing MCP',
            'transport' => 'auto',
            'endpoint' => 'https://changing.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
            'negotiated_era' => 'modern',
            'negotiated_version' => McpClient::MODERN_PROTOCOL_VERSION,
            'last_discovered_at' => now(),
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Changing',
            'status' => McpConnection::STATUS_ACTIVE,
            'last_discovered_at' => now(),
        ]);
        $tool = $this->tool($connection, 'records.lookup', 'mcp_changing_records_lookup', false);
        $this->markCatalogCurrent($connection);
        $transport = new RejectionThenSuccessTransport;
        McpClient::useTransportResolver(static fn () => $transport);

        $outcome = app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-1');

        $this->assertSame('completed', $outcome->status);
        $this->assertSame(['tools/call', 'server/discover', 'tools/call'], $transport->methods());
        Event::assertDispatched(
            McpToolInvocationFinished::class,
            static fn (McpToolInvocationFinished $event): bool => $event->provenance['recovery'] === 'renegotiated'
                && $event->provenance['physical_request_count'] === 3,
        );
    }

    private function tool(McpConnection $connection, string $remoteName, string $localName, bool $confirmation): McpConnectionTool
    {
        return McpConnectionTool::query()->create([
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => $remoteName,
            'local_name' => $localName,
            'input_schema_json' => ['type' => 'object'],
            'risk' => $confirmation ? 'write' : 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'read_only' => ! $confirmation,
            'idempotent' => ! $confirmation,
            'confirmation_required' => $confirmation,
        ]);
    }

    private function markCatalogCurrent(McpConnection $connection): void
    {
        $connection->forceFill([
            'catalog_hash' => app(McpToolCatalogFingerprint::class)->forConnection($connection),
        ])->save();
    }
}

final class InteractionTransport implements McpTransportContract
{
    /** @var list<JsonRpcMessage> */
    private array $requests = [];

    /** @param list<array<string,mixed>> $toolResponses */
    public function __construct(private array $toolResponses) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->requests[] = $request;
        if ($request->method === 'server/discover') {
            return JsonRpcMessage::response($request->id, [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['tools' => []],
            ]);
        }
        if ($request->method === 'tools/call') {
            $response = array_shift($this->toolResponses);

            return is_array($response)
                ? JsonRpcMessage::response($request->id, $response)
                : JsonRpcMessage::errorResponse($request->id, -32603, 'No scripted tool response.');
        }

        return JsonRpcMessage::errorResponse($request->id, -32601, 'Not scripted.');
    }

    public function notify(JsonRpcMessage $notification): void
    {
        $this->requests[] = $notification;
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /** @return list<JsonRpcMessage> */
    public function toolCalls(): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (JsonRpcMessage $request): bool => $request->method === 'tools/call',
        ));
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_values(array_filter(array_map(
            static fn (JsonRpcMessage $request): ?string => $request->method,
            $this->requests,
        )));
    }
}

final class RejectionThenSuccessTransport implements McpTransportContract
{
    /** @var list<JsonRpcMessage> */
    private array $requests = [];

    private bool $rejected = false;

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->requests[] = $request;
        if ($request->method === 'server/discover') {
            return JsonRpcMessage::response($request->id, [
                'protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION,
                'capabilities' => ['tools' => []],
            ]);
        }
        if ($request->method === 'tools/call' && ! $this->rejected) {
            $this->rejected = true;

            return JsonRpcMessage::errorResponse($request->id, -32601, 'Method version no longer supported.');
        }

        return JsonRpcMessage::response($request->id, [
            'content' => [['type' => 'text', 'text' => 'Recovered.']],
        ]);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        $this->requests[] = $notification;
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_values(array_filter(array_map(
            static fn (JsonRpcMessage $request): ?string => $request->method,
            $this->requests,
        )));
    }
}
