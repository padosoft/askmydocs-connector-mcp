<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Support\Facades\DB;
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

final class RemoteTaskLifecycleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('connector-mcp.enabled', true);
        $app['config']->set('connector-mcp.routes.middleware', ['web']);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_task_is_persisted_polled_resumed_and_actor_conversation_scoped(): void
    {
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $other = TestUser::query()->create(['name' => 'Other']);
        $tool = $this->tool();
        $transport = new RemoteTaskTransport;
        McpClient::useTransportResolver(static fn () => $transport);

        $outcome = app(McpToolExecutor::class)->invoke(
            $tool->local_name,
            ['query' => 'latest'],
            $actor,
            'conversation-1',
        );

        $this->assertSame('task_accepted', $outcome->status);
        $this->assertNotNull($outcome->taskId);
        $encrypted = (string) DB::table('mcp_connector_remote_tasks')->value('remote_task_id');
        $this->assertStringNotContainsString('remote-task-1', $encrypted);

        $this->actingAs($other)->getJson(
            '/api/conversations/mcp/tasks/'.$outcome->taskId.'?conversation_id=conversation-1',
        )->assertNotFound();
        $this->actingAs($actor)->getJson(
            '/api/conversations/mcp/tasks/'.$outcome->taskId.'?conversation_id=wrong',
        )->assertNotFound();
        DB::table('mcp_connector_remote_tasks')->update(['next_poll_at' => now()->subSecond()]);

        $this->actingAs($actor)->getJson(
            '/api/conversations/mcp/tasks/'.$outcome->taskId.'?conversation_id=conversation-1',
        )->assertOk()
            ->assertJsonPath('status', 'input_required')
            ->assertJsonPath('input_requests.approval.method', 'elicitation/create');

        $this->actingAs($actor)->postJson(
            '/api/conversations/mcp/tasks/'.$outcome->taskId.'/input',
            [
                'conversation_id' => 'conversation-1',
                'input_responses' => ['approval' => ['approved' => true]],
            ],
        )->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('artifact.text', 'Fresh task result.');

        $this->assertSame('remote-task-1', $transport->taskRequests[0]->params['taskId'] ?? null);
        $this->assertSame(
            ['approval' => ['approved' => true]],
            $transport->taskUpdates[0]->params['inputResponses'] ?? null,
        );
        $this->assertSame('state-1', $transport->taskUpdates[0]->params['requestState'] ?? null);
    }

    public function test_task_cancellation_is_idempotent_and_cooperative(): void
    {
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $tool = $this->tool();
        $transport = new RemoteTaskTransport;
        McpClient::useTransportResolver(static fn () => $transport);
        $outcome = app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-2');

        $endpoint = '/api/conversations/mcp/tasks/'.$outcome->taskId.'/cancel';
        $this->actingAs($actor)->postJson($endpoint, ['conversation_id' => 'conversation-2'])
            ->assertAccepted()
            ->assertJsonPath('status', 'working')
            ->assertJsonPath('cancel_requested', true);
        $this->actingAs($actor)->postJson($endpoint, ['conversation_id' => 'conversation-2'])
            ->assertAccepted()
            ->assertJsonPath('cancel_requested', true);

        $this->assertCount(1, $transport->taskCancellations);
    }

    private function tool(): McpConnectionTool
    {
        $server = McpServerDefinition::query()->create([
            'name' => 'Task MCP',
            'transport' => 'auto',
            'endpoint' => 'https://tasks.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Tasks',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);

        return McpConnectionTool::query()->create([
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'reports.generate',
            'local_name' => 'mcp_tasks_reports_generate',
            'input_schema_json' => ['type' => 'object'],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);
    }
}

final class RemoteTaskTransport implements McpTransportContract
{
    /** @var list<JsonRpcMessage> */
    public array $taskRequests = [];

    /** @var list<JsonRpcMessage> */
    public array $taskUpdates = [];

    /** @var list<JsonRpcMessage> */
    public array $taskCancellations = [];

    private int $polls = 0;

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        return match ($request->method) {
            'server/discover' => JsonRpcMessage::response($request->id, [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['extensions' => ['io.modelcontextprotocol/tasks' => []]],
            ]),
            'tools/call' => JsonRpcMessage::response($request->id, [
                'resultType' => 'task',
                'taskId' => 'remote-task-1',
                'status' => 'working',
                'ttlMs' => 60_000,
                'pollIntervalMs' => 250,
            ]),
            'tasks/get' => $this->getTask($request),
            'tasks/update' => $this->updateTask($request),
            'tasks/cancel' => $this->cancelTask($request),
            default => JsonRpcMessage::errorResponse($request->id, -32601, 'Not scripted.'),
        };
    }

    public function notify(JsonRpcMessage $notification): void {}

    public function isHealthy(): bool
    {
        return true;
    }

    private function getTask(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->taskRequests[] = $request;
        $this->polls++;
        if ($this->polls === 1) {
            return JsonRpcMessage::response($request->id, [
                'resultType' => 'complete',
                'taskId' => 'remote-task-1',
                'status' => 'input_required',
                'ttlMs' => 60_000,
                'pollIntervalMs' => 250,
                'requestState' => 'state-1',
                'inputRequests' => [
                    'approval' => ['method' => 'elicitation/create', 'params' => ['message' => 'Approve?']],
                ],
            ]);
        }

        return JsonRpcMessage::response($request->id, [
            'resultType' => 'complete',
            'taskId' => 'remote-task-1',
            'status' => 'completed',
            'ttlMs' => 60_000,
            'result' => ['content' => [['type' => 'text', 'text' => 'Fresh task result.']]],
        ]);
    }

    private function updateTask(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->taskUpdates[] = $request;

        return JsonRpcMessage::response($request->id, ['resultType' => 'complete']);
    }

    private function cancelTask(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->taskCancellations[] = $request;

        return JsonRpcMessage::response($request->id, ['resultType' => 'complete']);
    }
}
