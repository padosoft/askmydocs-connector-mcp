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
 * @property string $actor_id
 * @property string $conversation_id
 * @property string $resource_uri
 * @property array<string,mixed> $tool_input
 * @property array<string,mixed> $tool_result
 * @property array<string,mixed>|null $resource_snapshot
 * @property array<string,mixed>|null $model_context
 * @property Carbon $expires_at
 * @property McpConnection $connection
 * @property McpConnectionTool $tool
 */
final class McpAppInstance extends Model
{
    use BelongsToTenant;

    protected $table = 'mcp_connector_app_instances';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'mcp_connector_connection_id',
        'mcp_connector_tool_id',
        'actor_type',
        'actor_id',
        'conversation_id',
        'resource_uri',
        'tool_input',
        'tool_result',
        'resource_snapshot',
        'model_context',
        'expires_at',
    ];

    protected $hidden = [
        'resource_uri',
        'tool_input',
        'tool_result',
        'resource_snapshot',
        'model_context',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'resource_uri' => 'encrypted',
        'tool_input' => 'encrypted:array',
        'tool_result' => 'encrypted:array',
        'resource_snapshot' => 'encrypted:array',
        'model_context' => 'encrypted:array',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $instance): void {
            $instance->public_id ??= (string) Str::ulid();
        });
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
