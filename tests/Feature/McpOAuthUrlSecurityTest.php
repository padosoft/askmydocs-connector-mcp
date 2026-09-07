<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Padosoft\AskMyDocsConnectorMcp\Services\McpOAuthService;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class McpOAuthUrlSecurityTest extends TestCase
{
    /** @return iterable<string,array{0:string,1:bool}> */
    public static function localUrls(): iterable
    {
        yield 'localhost' => ['http://localhost/oauth/token', true];
        yield 'IPv4 loopback literal' => ['http://127.0.0.7/oauth/token', true];
        yield 'IPv6 loopback literal' => ['http://[::1]/oauth/token', true];
        yield 'lookalike public hostname' => ['http://127.attacker.example/oauth/token', false];
    }

    #[DataProvider('localUrls')]
    public function test_insecure_local_mode_accepts_only_real_loopback_hosts(string $url, bool $allowed): void
    {
        config()->set('connector-mcp.oauth.allow_insecure_local', true);
        $service = (new \ReflectionClass(McpOAuthService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(McpOAuthService::class, 'assertSecureOAuthUrl');

        if (! $allowed) {
            $this->expectException(\RuntimeException::class);
        } else {
            $this->addToAssertionCount(1);
        }

        $method->invoke($service, $url, 'token endpoint');
    }
}
