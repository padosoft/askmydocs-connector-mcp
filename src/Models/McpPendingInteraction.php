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
 * @property string $kind
 * @property array<string,mixed> $continuation
 * @property array<string,mixed>|null $prompt_json
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
final class McpPendingInteraction extends Model
{
    use BelongsToTenant;

    protected $table = 'mcp_connector_pending_interactions';

    protected $fillable = [
        'public_id', 'tenant_id', 'mcp_connector_connection_id', 'actor_type',
        'actor_id', 'conversation_id', 'kind', 'continuation', 'prompt_json',
        'expires_at', 'consumed_at',
    ];

    protected $hidden = ['continuation'];

    /** @var array<string,string> */
    protected $casts = [
        'continuation' => 'encrypted:array',
        'prompt_json' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $interaction): void {
            $interaction->public_id ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<McpConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connector_connection_id');
    }
}
