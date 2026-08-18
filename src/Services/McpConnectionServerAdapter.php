<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;

final readonly class McpConnectionServerAdapter implements McpServerContract
{
    public function __construct(
        private McpConnection $connection,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
    ) {}

    public function id(): string
    {
        return (string) $this->connection->public_id;
    }

    public function name(): string
    {
        return (string) $this->connection->label;
    }

    public function transport(): string
    {
        return match ($this->connection->server->transport) {
            'legacy_sse' => 'legacy_sse',
            'stdio_imported' => 'stdio',
            default => 'http',
        };
    }

    public function tenantId(): string
    {
        return (string) $this->connection->tenant_id;
    }

    /** @return array<string,mixed> */
    public function transportConfig(): array
    {
        $server = $this->connection->server;
        $headers = is_array($server->legacy_headers_encrypted) ? $server->legacy_headers_encrypted : [];
        $token = $this->vault->bearerToken($this->connection) ?? $this->vault->accessToken($this->connection);
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return [
            'endpoint' => $server->endpoint,
            'headers' => $headers,
            'timeout_ms' => (int) config('connector-mcp.http.timeout_seconds', 15) * 1000,
            'max_response_bytes' => (int) config('connector-mcp.http.max_response_bytes', 2_000_000),
            'before_request' => fn (string $endpoint) => $this->guard->assertAllowed($endpoint, $this->connection->isPersonal()),
        ];
    }

    /** @return list<string> */
    public function allowedTools(): array
    {
        return [];
    }

    public function isEnabled(): bool
    {
        return $this->connection->status === McpConnection::STATUS_ACTIVE;
    }
}
