<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string $public_id
 * @property string $tenant_id
 * @property string $actor_type
 * @property string|int $actor_id
 * @property string $conversation_id
 * @property string $invocation_id
 * @property string $remote_tool_name
 * @property string $local_tool_name
 * @property string $remote_task_id
 * @property string $remote_task_hash
 * @property string $status
 * @property string|null $status_message
 * @property array<array-key,mixed>|null $input_requests
 * @property string|null $request_state
 * @property array<string,mixed>|null $result_payload
 * @property array<string,mixed>|null $error_payload
 * @property array<string,mixed>|null $last_poll_error_json
 * @property array<string,mixed>|null $provenance_json
 * @property int $poll_interval_ms
 * @property Carbon|null $next_poll_at
 * @property Carbon|null $poll_lock_until
 * @property Carbon|null $remote_created_at
 * @property Carbon|null $remote_updated_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_polled_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property McpConnection $connection
 * @property McpConnectionTool|null $tool
 */
final class McpRemoteTask extends Model
{
    use BelongsToTenant;

    public const array TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'expired'];

    protected $table = 'mcp_connector_remote_tasks';

    protected $fillable = [
        'public_id', 'tenant_id', 'mcp_connector_connection_id', 'mcp_connector_tool_id',
        'actor_type', 'actor_id', 'conversation_id', 'invocation_id', 'remote_tool_name',
        'local_tool_name', 'remote_task_id', 'remote_task_hash', 'status', 'status_message',
        'input_requests', 'request_state', 'result_payload', 'error_payload',
        'last_poll_error_json', 'provenance_json', 'poll_interval_ms', 'next_poll_at',
        'poll_lock_until', 'remote_created_at', 'remote_updated_at', 'expires_at',
        'last_polled_at', 'cancel_requested_at', 'completed_at',
    ];

    protected $hidden = ['remote_task_id', 'remote_task_hash', 'request_state'];

    /** @var array<string,string> */
    protected $casts = [
        'remote_task_id' => 'encrypted',
        'input_requests' => 'encrypted:array',
        'request_state' => 'encrypted',
        'result_payload' => 'encrypted:array',
        'error_payload' => 'encrypted:array',
        'last_poll_error_json' => 'array',
        'provenance_json' => 'array',
        'poll_interval_ms' => 'integer',
        'next_poll_at' => 'datetime',
        'poll_lock_until' => 'datetime',
        'remote_created_at' => 'datetime',
        'remote_updated_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $task): void {
            $task->public_id ??= (string) Str::ulid();
        });
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** @return array<string,mixed> */
    public function toPublicArray(): array
    {
        return array_filter([
            'task_id' => $this->public_id,
            'status' => $this->status,
            'status_message' => $this->status_message,
            'poll_interval_ms' => $this->poll_interval_ms,
            'next_poll_at' => $this->next_poll_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'input_requests' => $this->input_requests,
            'artifact' => $this->result_payload,
            'error' => $this->error_payload,
            'cancel_requested' => $this->cancel_requested_at !== null,
            'terminal' => $this->isTerminal(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return BelongsTo<McpConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connector_connection_id');
    }

    /** @return BelongsTo<McpConnectionTool, $this> */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(McpConnectionTool::class, 'mcp_connector_tool_id');
    }
}
