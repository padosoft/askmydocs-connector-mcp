<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;

final readonly class McpToolCatalogFingerprint
{
    public function forConnection(McpConnection $connection): string
    {
        $catalog = DB::table((new McpConnectionTool)->getTable())
            ->select([
                'remote_name',
                'input_schema_json',
                'output_schema_json',
                'annotations_json',
                'meta_json',
            ])
            ->where('tenant_id', $connection->tenant_id)
            ->where('mcp_connector_connection_id', $connection->getKey())
            ->whereNull('removed_at')
            ->orderBy('remote_name')
            ->get()
            ->map(fn (\stdClass $tool): array => [
                'name' => $this->name($tool->remote_name ?? null),
                'inputSchema' => $this->jsonObject($tool->input_schema_json ?? null),
                'outputSchema' => $this->nullableJsonObject($tool->output_schema_json ?? null),
                'annotations' => $this->nullableJsonObject($tool->annotations_json ?? null),
                '_meta' => $this->nullableJsonObject($tool->meta_json ?? null),
            ])
            ->all();
        $catalog = $this->canonicalize($catalog);
        $encoded = json_encode(
            $catalog,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $encoded);
    }

    private function name(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException('The MCP tool catalog contains an invalid remote name.');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        $decoded = $this->nullableJsonObject($value);
        if ($decoded === null) {
            throw new \UnexpectedValueException('The MCP tool catalog contains a missing input schema.');
        }

        return $decoded;
    }

    /** @return array<string,mixed>|null */
    private function nullableJsonObject(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! is_array($value)) {
            throw new \UnexpectedValueException('The MCP tool catalog contains invalid JSON metadata.');
        }

        return $value;
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
