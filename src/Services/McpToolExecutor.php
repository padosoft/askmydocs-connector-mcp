<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Contracts\McpRuntimeGateContract;
use Padosoft\AskMyDocsConnectorMcp\Events\McpToolInvocationFinished;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Support\McpInvocationOutcome;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final readonly class McpToolExecutor
{
    public function __construct(
        private TenantContext $tenantContext,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
        private McpOAuthService $oauth,
        private McpPendingInteractionService $pending,
        private McpRemoteTaskService $tasks,
        private McpArtifactEnvelopeFactory $artifacts,
        private McpAppInstanceService $apps,
        private McpRuntimeGateContract $runtime,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $continuation
     */
    public function invoke(
        string $localName,
        array $arguments,
        Model $actor,
        string $conversationId,
        ?string $projectKey = null,
        bool $confirmed = false,
        array $continuation = [],
    ): McpInvocationOutcome {
        $this->assertRuntimeActive();
        $tool = $this->authorizedTool($localName, $actor, $projectKey);
        $connection = $tool->connection;
        if ($tool->confirmation_required && ! $confirmed) {
            $interaction = $this->pending->create(
                $connection,
                $actor,
                $conversationId,
                'confirmation',
                compact('localName', 'arguments', 'projectKey'),
                ['tool' => $localName, 'risk' => $tool->risk, 'message' => 'Confirm this MCP tool call.'],
            );

            return new McpInvocationOutcome('confirmation_required', pendingInteractionId: $interaction->public_id, prompt: $interaction->prompt_json);
        }

        $invocationId = (string) Str::uuid();
        $startedAt = microtime(true);
        $baseProvenance = [
            'server_id' => $connection->server->getKey(),
            'server_name' => $connection->server->name,
            'connection_id' => $connection->public_id,
            'tool_remote_name' => $tool->remote_name,
            'tool_local_name' => $tool->local_name,
            'invocation_id' => $invocationId,
            'timestamp' => now()->toIso8601String(),
        ];
        try {
            if ($connection->server->auth_mode === 'oauth') {
                $this->oauth->refreshIfNeeded($connection);
            }
            $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
            $result = $client->callToolResult((string) $tool->remote_name, $arguments, $continuation);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $provenance = $baseProvenance + ['latency_ms' => $latencyMs];

            if ($result->isTask()) {
                $task = $this->tasks->capture(
                    $tool,
                    $actor,
                    $conversationId,
                    $invocationId,
                    $result,
                    $provenance,
                );
                $taskPayload = $task->toPublicArray();
                $outcome = new McpInvocationOutcome(
                    status: 'task_accepted',
                    taskId: $task->public_id,
                    task: $taskPayload,
                    prompt: [
                        'message' => $task->status === 'input_required'
                            ? 'The MCP task requires additional input.'
                            : 'The MCP task is running.',
                        'inputRequests' => $task->input_requests,
                    ],
                );
                $this->emit(new McpToolInvocationFinished($tool, $arguments, $actor, $conversationId, $outcome, $provenance, $latencyMs));

                return $outcome;
            }

            $artifact = $this->artifacts->make($result, $provenance, (string) $actor->getKey());
            $app = $this->apps->capture($tool, $actor, $conversationId, $arguments, $result, $artifact);
            if ($app !== null) {
                $artifact = $artifact->withApp($app);
            }

            if ($result->isInputRequired()) {
                $interaction = $this->pending->create(
                    $connection,
                    $actor,
                    $conversationId,
                    'mrtr',
                    [
                        'localName' => $localName,
                        'arguments' => $arguments,
                        'projectKey' => $projectKey,
                        'requestState' => $result->requestState,
                    ],
                    ['inputRequests' => $result->inputRequests, 'message' => 'The MCP server requires additional input.'],
                );
                $outcome = new McpInvocationOutcome('input_required', $artifact, $interaction->public_id, $interaction->prompt_json);
                $this->emit(new McpToolInvocationFinished($tool, $arguments, $actor, $conversationId, $outcome, $provenance, $latencyMs));

                return $outcome;
            }

            $outcome = new McpInvocationOutcome($result->isError ? 'error' : 'completed', $artifact);
            $this->emit(new McpToolInvocationFinished($tool, $arguments, $actor, $conversationId, $outcome, $provenance, $latencyMs));

            return $outcome;
        } catch (\Throwable $exception) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->emit(new McpToolInvocationFinished(
                $tool,
                $arguments,
                $actor,
                $conversationId,
                null,
                $baseProvenance + ['latency_ms' => $latencyMs],
                $latencyMs,
                $exception,
            ));

            throw $exception;
        }
    }

    /** @param array<string,mixed> $response */
    public function resume(string $pendingId, array $response, Model $actor, string $conversationId): McpInvocationOutcome
    {
        $this->assertRuntimeActive();
        $interaction = $this->pending->consume($pendingId, $actor, $conversationId);
        $continuation = $interaction->continuation;
        $localName = (string) ($continuation['localName'] ?? '');
        $arguments = is_array($continuation['arguments'] ?? null) ? $continuation['arguments'] : [];
        $projectKey = is_string($continuation['projectKey'] ?? null) ? $continuation['projectKey'] : null;
        if ($interaction->kind === 'confirmation') {
            if (($response['confirmed'] ?? false) !== true) {
                return new McpInvocationOutcome('declined');
            }

            return $this->invoke($localName, $arguments, $actor, $conversationId, $projectKey, true);
        }

        return $this->invoke(
            $localName,
            $arguments,
            $actor,
            $conversationId,
            $projectKey,
            true,
            ['requestState' => $continuation['requestState'] ?? null, 'inputResponses' => $response],
        );
    }

    private function authorizedTool(string $localName, Model $actor, ?string $projectKey): McpConnectionTool
    {
        $query = McpConnectionTool::query();
        $query->whereHas('connection', function ($query) use ($actor, $projectKey): void {
            $query->where('status', 'active')
                ->where(function ($scope) use ($actor): void {
                    $scope->where('mode', 'shared')
                        ->orWhere(function ($personal) use ($actor): void {
                            $personal->where('mode', 'personal')
                                ->where('owner_type', $actor->getMorphClass())
                                ->where('owner_id', (string) $actor->getKey());
                        });
                })
                ->where(function ($projects) use ($projectKey): void {
                    $projects->whereNull('project_key');
                    if ($projectKey !== null) {
                        $projects->orWhere('project_key', $projectKey);
                    }
                });
        });
        $toolId = $query
            ->where('tenant_id', $this->tenantContext->current())
            ->where('local_name', $localName)
            ->where('enabled', true)
            ->whereNull('removed_at')
            ->value('id');
        if (! is_int($toolId)) {
            throw new ModelNotFoundException;
        }

        return McpConnectionTool::query()->with(['connection.server'])->findOrFail($toolId);
    }

    private function emit(McpToolInvocationFinished $event): void
    {
        try {
            event($event);
        } catch (\Throwable $exception) {
            try {
                report($exception);
            } catch (\Throwable) {
                // Observability must never change the tool invocation outcome.
            }
        }
    }

    private function assertRuntimeActive(): void
    {
        if (! $this->runtime->active($this->tenantContext->current())) {
            throw new \RuntimeException('The MCP connector runtime is not active for this tenant.');
        }
    }
}
