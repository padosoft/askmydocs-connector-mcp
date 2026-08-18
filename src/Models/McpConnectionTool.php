<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property int $mcp_connector_connection_id
 * @property string $remote_name
 * @property string $local_name
 * @property string|null $description
 * @property array<string,mixed> $input_schema_json
 * @property array<string,mixed>|null $output_schema_json
 * @property array<string,mixed>|null $annotations_json
 * @property array<string,mixed>|null $meta_json
 * @property string $risk
 * @property string $policy
 * @property bool $enabled
 * @property bool $confirmation_required
 * @property Carbon|null $removed_at
 * @property McpConnection $connection
 */
final class McpConnectionTool extends Model
{
    use BelongsToTenant;

    protected $table = 'mcp_connector_tools';

    protected $fillable = [
        'tenant_id',
        'mcp_connector_connection_id',
        'remote_name',
        'local_name',
        'title',
        'description',
        'input_schema_json',
        'output_schema_json',
        'annotations_json',
        'meta_json',
        'risk',
        'policy',
        'enabled',
        'read_only',
        'idempotent',
        'destructive',
        'confirmation_required',
        'discovered_at',
        'last_seen_at',
        'removed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'input_schema_json' => 'array',
        'output_schema_json' => 'array',
        'annotations_json' => 'array',
        'meta_json' => 'array',
        'enabled' => 'boolean',
        'read_only' => 'boolean',
        'idempotent' => 'boolean',
        'destructive' => 'boolean',
        'confirmation_required' => 'boolean',
        'discovered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    /** @return BelongsTo<McpConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connector_connection_id');
    }
}
