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
            // The resource-specific allow list is still applied to both the
            // outer host iframe and the inner sandbox iframe. This response
            // header must not pre-emptively deny permissions that an admin
            // explicitly enabled, otherwise browsers block them before the
            // iframe `allow` attributes can narrow access per app.
            'Permissions-Policy' => $this->permissionsPolicy(),
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

    private function permissionsPolicy(): string
    {
        $allowed = array_flip((array) config('connector-mcp.apps.allowed_permissions', []));
        $features = [
            'camera' => 'camera',
            'microphone' => 'microphone',
            'geolocation' => 'geolocation',
            'clipboard-write' => 'clipboardWrite',
        ];

        return implode(', ', array_map(
            static fn (string $feature, string $configKey): string => $feature.'='.(isset($allowed[$configKey]) ? '(*)' : '()'),
            array_keys($features),
            array_values($features),
        ));
    }
}
