<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;

final readonly class McpToolCatalogFingerprint
{
    public function forConnection(McpConnection $connection): string
    {
        $catalog = McpConnectionTool::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('mcp_connector_connection_id', $connection->getKey())
            ->whereNull('removed_at')
            ->orderBy('remote_name')
            ->get()
            ->map(static fn (McpConnectionTool $tool): array => [
                'name' => $tool->remote_name,
                'inputSchema' => $tool->input_schema_json,
                'outputSchema' => $tool->output_schema_json,
                'annotations' => $tool->annotations_json,
                '_meta' => $tool->meta_json,
            ])
            ->all();
        $catalog = $this->canonicalize($catalog);
        $encoded = json_encode(
            $catalog,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
