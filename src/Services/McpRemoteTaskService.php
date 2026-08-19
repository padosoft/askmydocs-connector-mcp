<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpRemoteTask;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\McpRemoteTask as WireTask;
use Padosoft\AskMyDocsMcpPack\Support\McpToolResult;

final readonly class McpRemoteTaskService
{
    /** @var list<string> */
    private const array STATUSES = ['working', 'input_required', 'completed', 'failed', 'cancelled'];

    public function __construct(
        private TenantContext $tenantContext,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
        private McpOAuthService $oauth,
        private McpArtifactEnvelopeFactory $artifacts,
    ) {}

    /** @param array<string,mixed> $provenance */
    public function capture(
        McpConnectionTool $tool,
        Model $actor,
        string $conversationId,
        string $invocationId,
        McpToolResult $result,
        array $provenance,
    ): McpRemoteTask {
        $wire = $result->remoteTask();
        if (! $wire instanceof WireTask) {
            throw new \UnexpectedValueException('MCP tool returned a task result without a valid task handle.');
        }
        $this->assertStatus($wire->status);
        $connection = $tool->connection()->with('server')->firstOrFail();
        $retention = $this->retentionSeconds();
        $task = McpRemoteTask::query()->create([
            'tenant_id' => $this->tenantContext->current(),
            'mcp_connector_connection_id' => $connection->getKey(),
            'mcp_connector_tool_id' => $tool->getKey(),
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => (string) $actor->getKey(),
            'conversation_id' => $conversationId,
            'invocation_id' => $invocationId,
            'remote_tool_name' => (string) $tool->remote_name,
            'local_tool_name' => (string) $tool->local_name,
            'remote_task_id' => $wire->taskId,
            'remote_task_hash' => hash('sha256', $wire->taskId),
            'status' => $wire->status,
            'poll_interval_ms' => $this->pollInterval($wire),
            'expires_at' => now()->addSeconds($retention),
            'provenance_json' => $provenance,
        ]);

        return $this->applyWireState($task, $wire);
    }

    public function status(string $publicId, Model $actor, string $conversationId, bool $poll = true): McpRemoteTask
    {
        $task = $this->scoped($publicId, $actor, $conversationId);

        return $poll ? $this->poll($task) : $task;
    }

    public function poll(McpRemoteTask $task, bool $force = false): McpRemoteTask
    {
        if ($this->expireIfNeeded($task) || $task->isTerminal()) {
            return $task->refresh();
        }
        if (! $force && $task->next_poll_at?->isFuture()) {
            return $task;
        }
        if (! $this->claim($task)) {
            return $task->refresh();
        }

        try {
            $wire = $this->client($task->connection)->getTaskStatus($task->remote_task_id);

            return $this->applyWireState($task->refresh(), $wire);
        } catch (\Throwable $exception) {
            $task->forceFill([
                'last_polled_at' => now(),
                'next_poll_at' => now()->addMilliseconds(max(1000, $task->poll_interval_ms)),
                'poll_lock_until' => null,
                'last_poll_error_json' => [
                    'class' => $exception::class,
                    'message' => 'Remote MCP task polling failed.',
                ],
            ])->save();

            return $task->refresh();
        }
    }

    /** @param array<string,mixed> $inputResponses */
    public function submitInput(
        string $publicId,
        array $inputResponses,
        Model $actor,
        string $conversationId,
        ?string $requestState = null,
    ): McpRemoteTask {
        $task = $this->scoped($publicId, $actor, $conversationId);
        if ($this->expireIfNeeded($task)) {
            throw new \DomainException('MCP task has expired.');
        }
        if ($task->status !== 'input_required' || $task->isTerminal()) {
            throw new \DomainException('MCP task is not waiting for input.');
        }
        $this->validateInputKeys($task, $inputResponses);
        if (! $this->claim($task)) {
            throw new \RuntimeException('MCP task is already being updated.');
        }

        try {
            $client = $this->client($task->connection);
            $client->updateTask(
                $task->remote_task_id,
                $inputResponses,
                $requestState ?? $task->request_state,
            );
            $wire = $client->getTaskStatus($task->remote_task_id);

            return $this->applyWireState($task->refresh(), $wire);
        } catch (\Throwable $exception) {
            $task->forceFill(['poll_lock_until' => null])->save();
            throw $exception;
        }
    }

    public function cancel(string $publicId, Model $actor, string $conversationId): McpRemoteTask
    {
        $task = $this->scoped($publicId, $actor, $conversationId);
        if ($this->expireIfNeeded($task)) {
            return $task->refresh();
        }
        if ($task->isTerminal() || $task->cancel_requested_at !== null) {
            return $task;
        }
        if (! $this->claim($task)) {
            throw new \RuntimeException('MCP task is already being updated.');
        }

        try {
            $this->client($task->connection)->cancelTask($task->remote_task_id);
            $task->forceFill([
                'cancel_requested_at' => now(),
                'last_polled_at' => now(),
                'next_poll_at' => now()->addMilliseconds($task->poll_interval_ms),
                'poll_lock_until' => null,
                'last_poll_error_json' => null,
            ])->save();

            return $task->refresh();
        } catch (\Throwable $exception) {
            $task->forceFill(['poll_lock_until' => null])->save();
            throw $exception;
        }
    }

    private function scoped(string $publicId, Model $actor, string $conversationId): McpRemoteTask
    {
        $task = McpRemoteTask::query()
            ->with(['connection.server', 'tool'])
            ->where('tenant_id', $this->tenantContext->current())
            ->where('public_id', $publicId)
            ->where('actor_type', $actor->getMorphClass())
            ->where('actor_id', (string) $actor->getKey())
            ->where('conversation_id', $conversationId)
            ->first();
        if (! $task instanceof McpRemoteTask) {
            throw new ModelNotFoundException;
        }

        return $task;
    }

    private function client(McpConnection $connection): McpClient
    {
        $connection->loadMissing('server');
        if ($connection->server->auth_mode === 'oauth') {
            $this->oauth->refreshIfNeeded($connection);
        }

        return McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
    }

    private function claim(McpRemoteTask $task): bool
    {
        $lockSeconds = max(1, (int) config('connector-mcp.tasks.poll_lock_seconds', 30));

        return DB::table('mcp_connector_remote_tasks')
            ->where('id', $task->getKey())
            ->where(function ($query): void {
                $query->whereNull('poll_lock_until')->orWhere('poll_lock_until', '<=', now());
            })
            ->update(['poll_lock_until' => now()->addSeconds($lockSeconds), 'updated_at' => now()]) === 1;
    }

    private function applyWireState(McpRemoteTask $task, WireTask $wire): McpRemoteTask
    {
        $this->assertStatus($wire->status);
        $pollInterval = $this->pollInterval($wire);
        $remoteCreatedAt = $this->parseTimestamp($wire->createdAt) ?? $task->remote_created_at;
        $remoteUpdatedAt = $this->parseTimestamp($wire->lastUpdatedAt) ?? $task->remote_updated_at;
        $expiresAt = $this->expiry($task, $wire, $remoteCreatedAt);
        $terminal = in_array($wire->status, ['completed', 'failed', 'cancelled'], true);
        $changes = [
            'status' => $wire->status,
            'status_message' => $wire->statusMessage,
            'input_requests' => $wire->requiresInput() ? $wire->inputRequests : null,
            'request_state' => $this->requestState($wire),
            'poll_interval_ms' => $pollInterval,
            'next_poll_at' => $terminal ? null : now()->addMilliseconds($pollInterval),
            'poll_lock_until' => null,
            'remote_created_at' => $remoteCreatedAt,
            'remote_updated_at' => $remoteUpdatedAt,
            'expires_at' => $expiresAt,
            'last_polled_at' => now(),
            'last_poll_error_json' => null,
            'completed_at' => $terminal ? ($task->completed_at ?? now()) : null,
        ];
        if ($wire->status === 'completed') {
            $changes['result_payload'] = $this->resultPayload($task, $wire);
            $changes['error_payload'] = null;
        } elseif ($wire->status === 'failed') {
            $changes['result_payload'] = null;
            $changes['error_payload'] = $this->safeRemoteError($wire->error);
        } elseif ($wire->status === 'cancelled') {
            $changes['result_payload'] = null;
            $changes['error_payload'] = null;
        }
        $task->forceFill($changes)->save();

        return $task->refresh();
    }

    /** @return array<string,mixed> */
    private function resultPayload(McpRemoteTask $task, WireTask $wire): array
    {
        if (! is_array($wire->result)) {
            return [
                'text' => '',
                'structuredContent' => null,
                'attachments' => [],
                '_meta' => [],
                'provenance' => $task->provenance_json ?? [],
                'isError' => true,
                'error' => 'Remote MCP task returned an invalid tool result.',
            ];
        }
        $provenance = ($task->provenance_json ?? []) + [
            'task_id' => $task->public_id,
            'task_completed_at' => now()->toIso8601String(),
        ];

        return $this->artifacts
            ->make(McpToolResult::fromArray($wire->result), $provenance, (string) $task->actor_id)
            ->toArray();
    }

    /** @return array<string,mixed> */
    private function safeRemoteError(mixed $error): array
    {
        $payload = is_array($error) ? $error : [];
        $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Remote MCP task failed.';
        $message = preg_replace('/(Bearer\s+)[^\s]+/i', '$1[redacted]', $message) ?? 'Remote MCP task failed.';
        $message = preg_replace('/(token|secret|password)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? 'Remote MCP task failed.';

        return array_filter([
            'code' => is_int($payload['code'] ?? null) ? $payload['code'] : null,
            'message' => mb_substr($message, 0, 1000),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function pollInterval(WireTask $wire): int
    {
        $minimum = max(50, (int) config('connector-mcp.tasks.minimum_poll_interval_ms', 250));
        $default = max($minimum, (int) config('connector-mcp.tasks.default_poll_interval_ms', 1000));

        return min(86_400_000, max($minimum, $wire->pollIntervalMs ?? $default));
    }

    private function expiry(McpRemoteTask $task, WireTask $wire, ?Carbon $remoteCreatedAt): CarbonInterface
    {
        $createdAt = $task->created_at ?? now();
        $localCap = $createdAt->copy()->addSeconds($this->retentionSeconds());
        if ($wire->ttlMs === null) {
            return $localCap;
        }
        $remoteExpiry = ($remoteCreatedAt ?? $createdAt)->copy()->addMilliseconds($wire->ttlMs);

        return $remoteExpiry->lessThan($localCap) ? $remoteExpiry : $localCap;
    }

    private function expireIfNeeded(McpRemoteTask $task): bool
    {
        if ($task->isTerminal() || ! $task->expires_at->isPast()) {
            return false;
        }
        $task->forceFill([
            'status' => 'expired',
            'next_poll_at' => null,
            'poll_lock_until' => null,
            'completed_at' => now(),
        ])->save();

        return true;
    }

    /** @param array<string,mixed> $responses */
    private function validateInputKeys(McpRemoteTask $task, array $responses): void
    {
        $requests = $task->input_requests ?? [];
        if ($responses === [] || $requests === [] || array_is_list($requests)) {
            return;
        }
        $unknown = array_diff(array_keys($responses), array_keys($requests));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('MCP task input contains unknown request keys.');
        }
    }

    private function requestState(WireTask $wire): ?string
    {
        $state = data_get($wire->raw, 'requestState', data_get($wire->raw, 'task.requestState'));

        return is_string($state) && $state !== '' ? $state : null;
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function retentionSeconds(): int
    {
        return max(60, (int) config('connector-mcp.tasks.retention_seconds', 604_800));
    }

    private function assertStatus(string $status): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \UnexpectedValueException("Unsupported MCP task status [{$status}].");
        }
    }
}
