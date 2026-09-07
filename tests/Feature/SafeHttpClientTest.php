<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorMcp\Exceptions\McpResponseTooLargeException;
use Padosoft\AskMyDocsConnectorMcp\Services\McpEndpointSecurityGuard;
use Padosoft\AskMyDocsConnectorMcp\Services\SafeHttpClient;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class SafeHttpClientTest extends TestCase
{
    public function test_response_body_is_capped_during_the_transfer(): void
    {
        config()->set('connector-mcp.http.max_response_bytes', 8);
        Http::fake([
            'public.example.test/*' => Http::response('123456789', 200),
        ]);

        $client = new SafeHttpClient(new McpEndpointSecurityGuard(
            static fn (string $host): array => ['8.8.8.8'],
        ));

        $this->expectException(McpResponseTooLargeException::class);
        $this->expectExceptionMessage('8 byte limit');
        $client->get('https://public.example.test/mcp');
    }

    public function test_streaming_handler_aborts_as_soon_as_multiple_chunks_cross_the_limit(): void
    {
        config()->set('connector-mcp.http.max_response_bytes', 8);
        $handler = static function ($request, array $options) {
            $response = new PsrResponse(200);
            $options['on_headers']($response);
            $options['sink']->write('12345');
            $options['sink']->write('6789');

            return Create::promiseFor($response);
        };
        $client = new SafeHttpClient(
            new McpEndpointSecurityGuard(static fn (string $host): array => ['8.8.8.8']),
            static fn (): PendingRequest => (new PendingRequest)->setHandler($handler),
        );

        $this->expectException(McpResponseTooLargeException::class);
        $this->expectExceptionMessage('8 byte limit');

        $client->get('https://public.example.test/mcp');
    }

    public function test_every_redirect_target_is_resolved_and_revalidated(): void
    {
        Http::fake([
            'public.example.test/*' => Http::response('', 302, [
                'Location' => 'https://private.example.test/mcp',
            ]),
        ]);
        $resolved = [];
        $client = new SafeHttpClient(new McpEndpointSecurityGuard(
            static function (string $host) use (&$resolved): array {
                $resolved[] = $host;

                return $host === 'private.example.test' ? ['10.0.0.2'] : ['8.8.8.8'];
            },
        ));

        try {
            $client->get('https://public.example.test/mcp');
            $this->fail('The private redirect target should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('non-public address', $exception->getMessage());
        }

        $this->assertSame(['public.example.test', 'private.example.test'], $resolved);
        Http::assertSentCount(1);
    }
}
