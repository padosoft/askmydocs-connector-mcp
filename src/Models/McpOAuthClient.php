<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string $client_id
 * @property string|null $client_secret
 */
final class McpOAuthClient extends Model
{
    use BelongsToTenant;

    protected $table = 'mcp_connector_oauth_clients';

    protected $fillable = [
        'tenant_id', 'strategy', 'issuer', 'issuer_hash', 'client_id',
        'client_secret', 'registration_metadata_json',
    ];

    protected $hidden = ['client_secret'];

    /** @var array<string,string> */
    protected $casts = [
        'client_secret' => 'encrypted',
        'registration_metadata_json' => 'array',
    ];
}
