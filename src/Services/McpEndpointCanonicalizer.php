<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

final class McpEndpointCanonicalizer
{
    public function canonicalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('MCP endpoint must be an absolute URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('MCP endpoint cannot contain credentials.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('MCP endpoint must use HTTP or HTTPS.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '') {
            throw new \InvalidArgumentException('MCP endpoint host is missing.');
        }
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii)) {
                $host = strtolower($ascii);
            }
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $authority = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $authority .= ':'.$port;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return "{$scheme}://{$authority}{$path}{$query}";
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path === '' ? '/' : $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
