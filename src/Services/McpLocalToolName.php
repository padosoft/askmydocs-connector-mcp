<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;

final class McpLocalToolName
{
    public function make(McpConnection $connection, string $remoteName): string
    {
        $connectionPrefix = strtolower(substr((string) $connection->public_id, 0, 8));
        $slug = Str::of($remoteName)->ascii()->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->toString();
        $slug = $slug === '' ? 'tool' : substr($slug, 0, 32);
        $hash = substr(hash('sha256', (string) $connection->public_id."\0".$remoteName), 0, 8);

        return "mcp_{$connectionPrefix}_{$slug}_{$hash}";
    }
}
