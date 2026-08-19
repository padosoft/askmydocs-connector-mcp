<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class McpAppSandboxController extends Controller
{
    public function __invoke(): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $ancestors = $this->frameAncestors();
        $content = view('connector-mcp::sandbox', ['nonce' => $nonce])->render();

        return response($content, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "script-src 'nonce-{$nonce}'",
                "style-src 'none'",
                "img-src 'none'",
                "font-src 'none'",
                "connect-src 'none'",
                'frame-src data: blob:',
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'none'",
                'frame-ancestors '.$ancestors,
            ]),
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), clipboard-write=()',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-AskMyDocs-MCP-App-Sandbox' => '1',
        ]);
    }

    private function frameAncestors(): string
    {
        $origins = [];
        foreach ((array) config('connector-mcp.apps.host_origins', []) as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $parts = parse_url(trim($candidate));
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
                continue;
            }
            $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
            $origins[] = $scheme.'://'.$host.$port;
        }

        return $origins === [] ? "'none'" : implode(' ', array_unique($origins));
    }
}
