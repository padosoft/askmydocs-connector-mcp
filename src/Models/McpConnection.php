<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string $public_id
 * @property string $tenant_id
 * @property int $mcp_connector_server_id
 * @property string $mode
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $label
 * @property string|null $project_key
 * @property string $status
 * @property array<string,mixed>|null $error_json
 * @property array<string,mixed>|null $granted_scopes_json
 * @property array<string,mixed>|null $account_metadata_json
 * @property McpServerDefinition $server
 */
final class McpConnection extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ERRORED = 'errored';

    public const STATUS_REAUTHORIZATION_REQUIRED = 'reauthorization_required';

    protected $table = 'mcp_connector_connections';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'mcp_connector_server_id',
        'mode',
        'owner_type',
        'owner_id',
        'label',
        'project_key',
        'granted_scopes_json',
        'account_metadata_json',
        'status',
        'last_authorized_at',
        'last_discovered_at',
        'error_json',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'granted_scopes_json' => 'array',
        'account_metadata_json' => 'array',
        'last_authorized_at' => 'datetime',
        'last_discovered_at' => 'datetime',
        'error_json' => 'array',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $connection): void {
            $connection->public_id ??= (string) Str::ulid();
            $connection->mode ??= $connection->owner_id !== null ? 'personal' : 'shared';
        });
    }

    public function isPersonal(): bool
    {
        return $this->mode === 'personal';
    }

    /** @return BelongsTo<McpServerDefinition, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(McpServerDefinition::class, 'mcp_connector_server_id');
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasOne<McpCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(McpCredential::class, 'mcp_connector_connection_id');
    }

    /** @return HasMany<McpConnectionTool, $this> */
    public function tools(): HasMany
    {
        return $this->hasMany(McpConnectionTool::class, 'mcp_connector_connection_id');
    }
}
