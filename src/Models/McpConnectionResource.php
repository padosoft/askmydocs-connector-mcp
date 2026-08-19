<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property int $mcp_connector_connection_id
 * @property string $uri
 * @property string $uri_hash
 * @property string|null $name
 * @property string|null $title
 * @property string|null $mime_type
 * @property bool $enabled
 * @property string|null $content_hash
 * @property McpConnection $connection
 */
final class McpConnectionResource extends Model
{
    use BelongsToTenant;

    protected $table = 'mcp_connector_resources';

    protected $fillable = [
        'tenant_id',
        'mcp_connector_connection_id',
        'uri',
        'uri_hash',
        'name',
        'title',
        'description',
        'mime_type',
        'size',
        'annotations_json',
        'meta_json',
        'enabled',
        'content_hash',
        'discovered_at',
        'last_seen_at',
        'last_ingested_at',
        'removed_at',
        'ingest_error_json',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'size' => 'integer',
        'annotations_json' => 'array',
        'meta_json' => 'array',
        'enabled' => 'boolean',
        'discovered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_ingested_at' => 'datetime',
        'removed_at' => 'datetime',
        'ingest_error_json' => 'array',
    ];

    /** @return BelongsTo<McpConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connector_connection_id');
    }
}
