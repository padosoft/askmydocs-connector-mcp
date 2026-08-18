<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorMcp\Contracts\SafeHttpClientContract;

final class SafeHttpClient implements SafeHttpClientContract
{
    public function __construct(private readonly McpEndpointSecurityGuard $guard) {}

    /** @param array<string,string> $headers */
    public function get(string $url, array $headers = [], bool $personal = true): Response
    {
        return $this->request('GET', $url, [], $headers, $personal);
    }

    /**
     * @param  array<string,mixed>  $form
     * @param  array<string,string>  $headers
     */
    public function postForm(string $url, array $form, array $headers = [], bool $personal = true): Response
    {
        return $this->request('POST_FORM', $url, $form, $headers, $personal);
    }

    /**
     * @param  array<string,mixed>  $json
     * @param  array<string,string>  $headers
     */
    public function postJson(string $url, array $json, array $headers = [], bool $personal = true): Response
    {
        return $this->request('POST_JSON', $url, $json, $headers, $personal);
    }

    /**
     * @param  array<string,mixed>  $form
     * @param  array<string,string>  $headers
     */
    private function request(string $method, string $url, array $form, array $headers, bool $personal): Response
    {
        $maxRedirects = max(0, (int) config('connector-mcp.http.max_redirects', 3));
        $origin = $this->origin($url);

        for ($redirects = 0; ; $redirects++) {
            // Resolve and validate immediately before every outbound request.
            $this->guard->assertAllowed($url, $personal);
            $request = Http::connectTimeout((int) config('connector-mcp.http.connect_timeout_seconds', 5))
                ->timeout((int) config('connector-mcp.http.timeout_seconds', 15))
                ->withoutRedirecting()
                ->withHeaders($headers);
            $response = match ($method) {
                'POST_FORM' => $request->asForm()->post($url, $form),
                'POST_JSON' => $request->asJson()->post($url, $form),
                default => $request->get($url),
            };

            if (strlen($response->body()) > (int) config('connector-mcp.http.max_response_bytes', 2_000_000)) {
                throw new \RuntimeException('MCP HTTP response exceeded the configured size limit.');
            }
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            if ($redirects >= $maxRedirects) {
                throw new \RuntimeException('MCP HTTP request exceeded the redirect limit.');
            }

            $location = $response->header('Location');
            if ($location === '') {
                throw new \RuntimeException('MCP HTTP redirect omitted the Location header.');
            }
            $url = $this->resolveLocation($url, $location);
            if ($this->origin($url) !== $origin) {
                unset($headers['Authorization'], $headers['authorization']);
                $origin = $this->origin($url);
            }
            if ($response->status() === 303 || (in_array($response->status(), [301, 302], true) && str_starts_with($method, 'POST'))) {
                $method = 'GET';
                $form = [];
            }
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return strtolower((string) ($parts['scheme'] ?? '')).'://'.strtolower((string) ($parts['host'] ?? '')).':'.(string) ($parts['port'] ?? '');
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Cannot resolve redirect URL.');
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        if (str_starts_with($location, '/')) {
            return $parts['scheme'].'://'.$parts['host'].$port.$location;
        }
        $path = (string) ($parts['path'] ?? '/');

        return $parts['scheme'].'://'.$parts['host'].$port.rtrim(dirname($path), '/').'/'.$location;
    }
}
