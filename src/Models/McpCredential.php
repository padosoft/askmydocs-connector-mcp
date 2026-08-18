<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $bearer_token
 * @property string $credential_type
 * @property string|null $issuer
 * @property string|null $resource
 * @property Carbon|null $expires_at
 * @property array<int,string>|null $scopes_json
 * @property int $rotation_version
 */
final class McpCredential extends Model
{
    use BelongsToTenant;

    protected $table = 'mcp_connector_credentials';

    protected $fillable = [
        'tenant_id',
        'mcp_connector_connection_id',
        'credential_type',
        'bearer_token',
        'access_token',
        'refresh_token',
        'token_type',
        'issuer',
        'resource',
        'scopes_json',
        'expires_at',
        'rotation_version',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'bearer_token',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'bearer_token' => 'encrypted',
        'scopes_json' => 'array',
        'expires_at' => 'datetime',
        'rotation_version' => 'integer',
    ];

    /** @return BelongsTo<McpConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connector_connection_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
