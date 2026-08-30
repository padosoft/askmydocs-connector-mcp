<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Support;

/**
 * Sanitizes optional AskMyDocs routing hints carried by MCP tool metadata.
 * These fields are advisory only; authorization, risk and confirmation remain
 * host-enforced and are deliberately not part of this contract.
 */
final class McpAgentCapabilityHint
{
    private const OPERATIONS = ['search', 'list', 'get', 'detail', 'summary', 'count', 'check'];

    /** @return array<string,mixed>|null */
    public static function fromMeta(mixed $meta): ?array
    {
        $raw = is_array($meta) ? ($meta['askmydocs/agent-capability'] ?? null) : null;
        if (! is_array($raw)) {
            return null;
        }
        $operation = self::identifier($raw['operation'] ?? null);
        if ($operation !== null && ! in_array($operation, self::OPERATIONS, true)) {
            $operation = null;
        }
        $hint = array_filter([
            'entity' => self::identifier($raw['entity'] ?? null),
            'operation' => $operation,
            'intent_tags' => self::identifiers($raw['intent_tags'] ?? null, 12),
            'requires' => self::identifiers($raw['requires'] ?? null, 20),
            'produces' => self::identifiers($raw['produces'] ?? null, 20),
            'collection_path' => self::path($raw['collection_path'] ?? null),
            'identity_fields' => self::identifiers($raw['identity_fields'] ?? null, 12),
            'next_tools' => self::identifiers($raw['next_tools'] ?? null, 12),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        return $hint === [] ? null : $hint;
    }

    private static function identifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return $value !== '' && strlen($value) <= 80
            && preg_match('/^[a-z0-9][a-z0-9_.:-]*$/', $value) === 1 ? $value : null;
    }

    /** @return list<string> */
    private static function identifiers(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_slice(array_filter(array_map(
            static fn (mixed $item): ?string => self::identifier($item),
            $value,
        )), 0, $limit)));
    }

    private static function path(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 160
            && preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_*-]+)*$/', $value) === 1 ? $value : null;
    }
}
