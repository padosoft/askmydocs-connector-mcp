<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string $name
 * @property string $catalog_scope
 * @property string $transport
 * @property string $auth_mode
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property array<string,string>|null $legacy_headers_encrypted
 * @property array<string,mixed>|null $oauth_metadata_json
 * @property string $status
 */
final class McpServerDefinition extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ERRORED = 'errored';

    protected $table = 'mcp_connector_servers';

    protected $hidden = ['legacy_headers_encrypted'];

    protected $fillable = [
        'tenant_id',
        'name',
        'catalog_scope',
        'owner_type',
        'owner_id',
        'transport',
        'auth_mode',
        'endpoint',
        'endpoint_hash',
        'legacy_headers_encrypted',
        'oauth_metadata_json',
        'negotiated_era',
        'negotiated_version',
        'capabilities_json',
        'server_info_json',
        'status',
        'last_discovered_at',
        'error_json',
        'created_by',
        'legacy_reference',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'oauth_metadata_json' => 'array',
        'legacy_headers_encrypted' => 'encrypted:array',
        'capabilities_json' => 'array',
        'server_info_json' => 'array',
        'last_discovered_at' => 'datetime',
        'error_json' => 'array',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $server): void {
            if (empty($server->endpoint_hash)) {
                $server->endpoint_hash = hash('sha256', $server->endpoint);
            }
        });
    }

    /** @return HasMany<McpConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(McpConnection::class, 'mcp_connector_server_id');
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
