<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorMcp\Services\McpEndpointCanonicalizer;
use Padosoft\AskMyDocsConnectorMcp\Services\McpEndpointSecurityGuard;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class EndpointSecurityTest extends TestCase
{
    public function test_endpoint_is_canonicalized_without_credentials_or_fragments(): void
    {
        $canonicalizer = new McpEndpointCanonicalizer;

        $this->assertSame(
            'https://example.com/mcp?q=1',
            $canonicalizer->canonicalize('HTTPS://Example.COM:443/a/../mcp?q=1#secret'),
        );
    }

    public function test_personal_endpoints_require_https_and_every_dns_answer_must_be_public(): void
    {
        $guard = new McpEndpointSecurityGuard(static fn (string $host): array => $host === 'mixed.example.test'
            ? ['8.8.8.8', '10.0.0.2']
            : ['8.8.8.8']);

        $this->expectException(\InvalidArgumentException::class);
        $guard->assertAllowed('https://mixed.example.test/mcp');
    }

    public function test_personal_endpoint_rejects_plain_http_even_with_public_dns(): void
    {
        $guard = new McpEndpointSecurityGuard(static fn (string $host): array => ['8.8.8.8']);

        $this->expectException(\InvalidArgumentException::class);
        $guard->assertAllowed('http://public.example.test/mcp');
    }
}
