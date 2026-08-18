<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolExecutor;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

final class ToolInteractionTest extends TestCase
{
    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_write_confirmation_and_mrtr_resume_the_original_tool_call(): void
    {
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
            'confirmation_required' => $confirmation,
        ]);
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
}
