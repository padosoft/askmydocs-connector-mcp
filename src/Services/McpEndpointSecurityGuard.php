<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

final class McpEndpointSecurityGuard
{
    /** @var \Closure(string):list<string> */
    private readonly \Closure $resolver;

    /** @param (callable(string):list<string>)|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? \Closure::fromCallable($resolver) : $this->defaultResolver(...);
    }

    public function assertAllowed(string $url, bool $personal = true): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid MCP endpoint URL.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($personal && strtolower((string) $parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('Personal MCP endpoints must use HTTPS.');
        }
        if ($this->isAllowlisted($host)) {
            return;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new \InvalidArgumentException('Local MCP endpoints are not allowed.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($addresses === []) {
            throw new \InvalidArgumentException('MCP endpoint did not resolve to an IP address.');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new \InvalidArgumentException("MCP endpoint resolves to a non-public address [{$address}].");
            }
        }
    }

    private function isPublicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($address === '169.254.169.254' || $address === '100.100.100.200') {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function isAllowlisted(string $host): bool
    {
        $allowlist = config('connector-mcp.http.internal_endpoint_allowlist', []);

        return is_array($allowlist) && in_array($host, array_map(static fn (mixed $item): string => strtolower((string) $item), $allowlist), true);
    }

    /** @return list<string> */
    private function defaultResolver(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
