<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $pkce_verifier
 * @property string $issuer
 * @property string $resource
 * @property string $token_endpoint
 * @property string $client_id
 * @property string|null $client_secret
 * @property bool $authorization_response_iss_parameter_supported
 * @property array<int,string>|null $scopes_json
 * @property string|null $ui_destination
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
final class McpOAuthAttempt extends Model
{
    use BelongsToTenant;

    protected $table = 'mcp_connector_oauth_attempts';

    protected $fillable = [
        'tenant_id', 'mcp_connector_connection_id', 'owner_type', 'owner_id',
        'state_hash', 'pkce_verifier', 'issuer', 'resource',
        'authorization_endpoint', 'token_endpoint', 'client_id', 'client_secret',
        'authorization_response_iss_parameter_supported', 'scopes_json',
        'ui_destination', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['state_hash', 'pkce_verifier', 'client_secret'];

    /** @var array<string,string> */
    protected $casts = [
        'pkce_verifier' => 'encrypted',
        'client_secret' => 'encrypted',
        'authorization_response_iss_parameter_supported' => 'boolean',
        'scopes_json' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /** @return BelongsTo<McpConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connector_connection_id');
    }
}
