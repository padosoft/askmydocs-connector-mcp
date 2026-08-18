<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Support\McpArtifactEnvelope;
use Padosoft\AskMyDocsMcpPack\Artifacts\Artifact;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Support\McpToolResult;

final class McpArtifactEnvelopeFactory
{
    public function __construct(
        private readonly ArtifactManagerContract $artifacts,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param array<string,mixed> $provenance */
    public function make(McpToolResult $result, array $provenance, ?string $actorId = null): McpArtifactEnvelope
    {
        $texts = [];
        $attachments = [];
        foreach ($result->content as $block) {
            $type = (string) ($block['type'] ?? 'unknown');
            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];

                continue;
            }
            if (in_array($type, ['image', 'audio'], true)) {
                $data = is_string($block['data'] ?? null) ? $block['data'] : '';
                $mime = is_string($block['mimeType'] ?? null) ? $block['mimeType'] : 'application/octet-stream';
                $attachments[] = $this->storedAttachment(
                    type: $type,
                    encoded: $data,
                    mimeType: $mime,
                    name: 'mcp-'.$type,
                    actorId: $actorId,
                    provenance: $provenance,
                    fallback: "[{$type} attachment from MCP tool]",
                ) + [
                    'type' => $type,
                    'mimeType' => $mime,
                ];

                continue;
            }
            if (in_array($type, ['resource_link', 'resource'], true)) {
                $attachments[] = $this->resourceAttachment($block, $actorId, $provenance);

                continue;
            }
            $attachments[] = ['type' => $type, 'fallback' => '[Unsupported MCP content block]'];
        }

        $limit = max(1, (int) config('connector-mcp.llm_text_limit', 24_000));
        $text = implode("\n\n", $texts);
        if ($result->structuredContent !== null) {
            $json = json_encode($result->structuredContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $text .= ($text === '' ? '' : "\n\n").$json;
            }
        }
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit).'… [truncated]';
        }

        return new McpArtifactEnvelope(
            llmText: $text,
            structuredContent: $result->structuredContent,
            attachments: $attachments,
            meta: $result->meta,
            provenance: $provenance,
            isError: $result->isError,
        );
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  array<string,mixed>  $provenance
     * @return array<string,mixed>
     */
    private function resourceAttachment(array $block, ?string $actorId, array $provenance): array
    {
        if (($block['type'] ?? null) === 'resource_link') {
            return array_filter([
                'type' => 'resource_link',
                'uri' => is_string($block['uri'] ?? null) ? $block['uri'] : null,
                'name' => is_string($block['name'] ?? null) ? $block['name'] : null,
                'title' => is_string($block['title'] ?? null) ? $block['title'] : null,
                'description' => is_string($block['description'] ?? null) ? $block['description'] : null,
                'mimeType' => is_string($block['mimeType'] ?? null) ? $block['mimeType'] : null,
                'size' => is_int($block['size'] ?? null) ? $block['size'] : null,
                '_meta' => is_array($block['_meta'] ?? null) ? $block['_meta'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $resource = is_array($block['resource'] ?? null) ? $block['resource'] : [];
        $text = is_string($resource['text'] ?? null) ? $resource['text'] : null;
        $blob = is_string($resource['blob'] ?? null) ? $resource['blob'] : null;
        $mime = is_string($resource['mimeType'] ?? null) ? $resource['mimeType'] : ($text !== null ? 'text/plain' : 'application/octet-stream');
        $uri = is_string($resource['uri'] ?? null) ? $resource['uri'] : null;
        $stored = $blob !== null
            ? $this->storedAttachment('resource', $blob, $mime, $this->resourceName($uri), $actorId, $provenance, '[Embedded MCP resource attachment]')
            : $this->storedAttachment('resource', base64_encode($text ?? ''), $mime, $this->resourceName($uri), $actorId, $provenance, '[Embedded MCP resource attachment]');

        return array_filter($stored + [
            'type' => 'resource',
            'uri' => $uri,
            'mimeType' => $mime,
            '_meta' => is_array($block['_meta'] ?? null) ? $block['_meta'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $provenance
     * @return array<string,mixed>
     */
    private function storedAttachment(
        string $type,
        string $encoded,
        string $mimeType,
        string $name,
        ?string $actorId,
        array $provenance,
        string $fallback,
    ): array {
        $bytes = base64_decode($encoded, true);
        if (! is_string($bytes)) {
            return [
                'sha256' => hash('sha256', $encoded),
                'encodedBytes' => strlen($encoded),
                'fallback' => $fallback,
                'storage' => 'invalid_base64',
            ];
        }

        try {
            $artifact = $this->artifacts->create(
                Artifact::make($name)
                    ->mimeType($mimeType)
                    ->contents($bytes)
                    ->metadata(['source' => 'mcp_connector', 'type' => $type, 'provenance' => $provenance]),
                $this->tenantContext->current(),
                $actorId,
            );

            return [
                'artifactId' => (string) $artifact->getKey(),
                'downloadUrl' => $this->artifacts->temporaryUrl((string) $artifact->getKey(), $this->tenantContext->current(), $actorId),
                'sha256' => (string) $artifact->sha256,
                'bytes' => (int) $artifact->size_bytes,
                'fallback' => $fallback,
            ];
        } catch (\Throwable) {
            return [
                'sha256' => hash('sha256', $bytes),
                'bytes' => strlen($bytes),
                'fallback' => $fallback,
                'storage' => 'unavailable',
            ];
        }
    }

    private function resourceName(?string $uri): string
    {
        if ($uri === null || $uri === '') {
            return 'mcp-resource';
        }
        $path = parse_url($uri, PHP_URL_PATH);
        $name = is_string($path) ? basename($path) : '';

        return $name !== '' && $name !== '/' ? $name : 'mcp-resource';
    }
}
