<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Services\McpArtifactEnvelopeFactory;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Models\McpArtifact;
use Padosoft\AskMyDocsMcpPack\Support\McpToolResult;

final class ArtifactEnvelopeTest extends TestCase
{
    public function test_binary_and_embedded_content_is_stored_privately_and_replaced_by_signed_references(): void
    {
        app(TenantContext::class)->set('acme');
        $result = McpToolResult::fromArray([
            'content' => [
                ['type' => 'text', 'text' => 'Fresh result'],
                ['type' => 'image', 'data' => base64_encode('image-bytes'), 'mimeType' => 'image/png'],
                ['type' => 'resource', 'resource' => [
                    'uri' => 'https://mcp.example.test/files/report.txt',
                    'mimeType' => 'text/plain',
                    'text' => 'embedded resource',
                ]],
            ],
            'structuredContent' => ['answer' => 42],
            '_meta' => ['ui' => ['resourceUri' => 'ui://result/widget.html']],
        ]);

        $envelope = app(McpArtifactEnvelopeFactory::class)->make(
            $result,
            ['connection_id' => 'connection-1', 'invocation_id' => 'invocation-1'],
            'user-1',
        )->toArray();

        $this->assertSame(2, McpArtifact::query()->count());
        $this->assertStringContainsString('Fresh result', $envelope['text']);
        $this->assertStringContainsString('"answer":42', $envelope['text']);
        $this->assertSame('ui://result/widget.html', $envelope['_meta']['ui']['resourceUri']);
        foreach ($envelope['attachments'] as $attachment) {
            $this->assertArrayHasKey('artifactId', $attachment);
            $this->assertStringContainsString('signature=', $attachment['downloadUrl']);
            $this->assertArrayNotHasKey('data', $attachment);
            $this->assertArrayNotHasKey('blob', $attachment);
        }
    }
}
