<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpCredential;

/**
 * The only package service allowed to expose decrypted MCP OAuth tokens.
 *
 * Models encrypt both token columns through Laravel's encrypted cast. Every
 * lookup is additionally constrained by the active tenant so possession of a
 * connection id cannot cross the tenant boundary.
 */
final class McpCredentialVault
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  list<string>  $scopes
     */
    public function put(
        McpConnection $connection,
        string $accessToken,
        ?string $refreshToken = null,
        ?CarbonInterface $expiresAt = null,
        array $scopes = [],
        string $tokenType = 'Bearer',
        ?string $issuer = null,
        ?string $resource = null,
    ): McpCredential {
        $this->assertOwnedByCurrentTenant($connection);

        return McpCredential::query()->updateOrCreate(
            [
                'tenant_id' => $connection->tenant_id,
                'mcp_connector_connection_id' => $connection->getKey(),
            ],
            [
                'credential_type' => 'oauth',
                'bearer_token' => null,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => $tokenType,
                'issuer' => $issuer,
                'resource' => $resource,
                'scopes_json' => $scopes === [] ? null : $scopes,
                'expires_at' => $expiresAt,
            ],
        );
    }

    public function putBearer(McpConnection $connection, string $token): McpCredential
    {
        $this->assertOwnedByCurrentTenant($connection);
        if (trim($token) === '') {
            throw new \InvalidArgumentException('Bearer token cannot be empty.');
        }

        return McpCredential::query()->updateOrCreate(
            [
                'tenant_id' => $connection->tenant_id,
                'mcp_connector_connection_id' => $connection->getKey(),
            ],
            [
                'credential_type' => 'bearer',
                'bearer_token' => $token,
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
            ],
        );
    }

    public function bearerToken(McpConnection $connection): ?string
    {
        return $this->credential($connection)?->bearer_token;
    }

    public function accessToken(McpConnection $connection): ?string
    {
        $credential = $this->credential($connection);

        if ($credential === null || $credential->isExpired()) {
            return null;
        }

        return $credential->access_token;
    }

    public function refreshToken(McpConnection $connection): ?string
    {
        return $this->credential($connection)?->refresh_token;
    }

    public function oauthCredential(McpConnection $connection): ?McpCredential
    {
        $credential = $this->credential($connection);

        return $credential?->credential_type === 'oauth' ? $credential : null;
    }

    /**
     * Serializes refresh-token rotation with a row lock. The callback receives
     * the current decrypted credential and must return OAuth token payload.
     *
     * @param  callable(McpCredential):array<string,mixed>  $refresh
     */
    public function rotate(McpConnection $connection, callable $refresh): McpCredential
    {
        return $this->rotateLocked($connection, $refresh, false);
    }

    /**
     * Refreshes only when the credential is still expired after acquiring the
     * row lock. This prevents two concurrent requests from both rotating the
     * same refresh token.
     *
     * @param  callable(McpCredential):array<string,mixed>  $refresh
     */
    public function rotateExpired(McpConnection $connection, callable $refresh): McpCredential
    {
        return $this->rotateLocked($connection, $refresh, true);
    }

    /** @param callable(McpCredential):array<string,mixed> $refresh */
    private function rotateLocked(McpConnection $connection, callable $refresh, bool $onlyIfExpired): McpCredential
    {
        $this->assertOwnedByCurrentTenant($connection);

        return DB::transaction(function () use ($connection, $refresh, $onlyIfExpired): McpCredential {
            $credentialId = DB::table('mcp_connector_credentials')
                ->where('tenant_id', $this->tenantContext->current())
                ->where('mcp_connector_connection_id', $connection->getKey())
                ->lockForUpdate()
                ->value('id');
            if (! is_int($credentialId)) {
                throw new ModelNotFoundException;
            }
            $credential = McpCredential::query()->findOrFail($credentialId);
            if ($onlyIfExpired && ! $credential->isExpired()) {
                return $credential;
            }
            $payload = $refresh($credential);
            $accessToken = $payload['access_token'] ?? null;
            if (! is_string($accessToken) || $accessToken === '') {
                throw new \UnexpectedValueException('OAuth refresh response did not contain an access_token.');
            }

            $credential->forceFill([
                'credential_type' => 'oauth',
                'access_token' => $accessToken,
                'refresh_token' => is_string($payload['refresh_token'] ?? null)
                    ? $payload['refresh_token']
                    : $credential->refresh_token,
                'token_type' => is_string($payload['token_type'] ?? null) ? $payload['token_type'] : 'Bearer',
                'scopes_json' => is_string($payload['scope'] ?? null)
                    ? preg_split('/\s+/', trim($payload['scope']), -1, PREG_SPLIT_NO_EMPTY)
                    : $credential->scopes_json,
                'expires_at' => isset($payload['expires_in']) ? now()->addSeconds(max(0, (int) $payload['expires_in'])) : null,
                'rotation_version' => ((int) $credential->rotation_version) + 1,
            ])->save();

            return $credential->refresh();
        }, 3);
    }

    public function clear(McpConnection $connection): bool
    {
        $this->assertOwnedByCurrentTenant($connection);

        return McpCredential::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mcp_connector_connection_id', $connection->getKey())
            ->delete() === 1;
    }

    private function credential(McpConnection $connection): ?McpCredential
    {
        $this->assertOwnedByCurrentTenant($connection);

        return McpCredential::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('mcp_connector_connection_id', $connection->getKey())
            ->first();
    }

    private function assertOwnedByCurrentTenant(McpConnection $connection): void
    {
        if ((string) $connection->tenant_id !== $this->tenantContext->current()) {
            throw new \InvalidArgumentException('The MCP connection does not belong to the active tenant.');
        }
    }
}
