<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Contracts\SafeHttpClientContract;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpOAuthAttempt;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpCredentialVault;
use Padosoft\AskMyDocsConnectorMcp\Services\McpOAuthService;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class McpOAuthServiceTest extends TestCase
{
    public function test_pkce_cimd_scope_resource_issuer_and_single_use_callback_are_enforced(): void
    {
        $http = new OAuthFakeHttpClient;
        $this->app->instance(SafeHttpClientContract::class, $http);
        config()->set('app.url', 'https://askmydocs.example');
        config()->set('connector-mcp.oauth.client_metadata_url', 'https://askmydocs.example/.well-known/mcp-client.json');
        $this->metadataResponses($http);
        $http->respond('POST_FORM', 'https://auth.example.test/tenant/token', 200, [
            'access_token' => 'access-one',
            'refresh_token' => 'refresh-one',
            'token_type' => 'Bearer',
            'scope' => 'files:read',
            'expires_in' => 3600,
        ]);

        [$connection, $owner] = $this->personalConnection();
        $start = app(McpOAuthService::class)->begin(
            $connection,
            $owner,
            wwwAuthenticate: 'Bearer resource_metadata="https://mcp.example.test/.well-known/oauth-protected-resource/mcp", scope="files:read"',
            uiDestination: '/app/connected-apps',
        );

        parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('files:read', $query['scope']);
        $this->assertSame('https://mcp.example.test/mcp', $query['resource']);
        $this->assertSame('https://askmydocs.example/.well-known/mcp-client.json', $query['client_id']);
        $this->assertArrayHasKey('code_challenge', $query);

        $attempt = McpOAuthAttempt::query()->firstOrFail();
        $raw = DB::table('mcp_connector_oauth_attempts')->where('id', $attempt->id)->firstOrFail();
        $this->assertNotSame($attempt->pkce_verifier, $raw->pkce_verifier);
        $this->assertNotSame($query['state'], $raw->state_hash);

        try {
            app(McpOAuthService::class)->callback((string) $query['state'], 'code-one', $owner, 'https://evil.example');
            $this->fail('Issuer mismatch should have been rejected.');
        } catch (AuthorizationException) {
            $this->assertNull($attempt->fresh()->consumed_at);
        }

        $callback = app(McpOAuthService::class)->callback(
            (string) $query['state'],
            'code-one',
            $owner,
            'https://auth.example.test/tenant',
        );
        $this->assertSame('/app/connected-apps', $callback['destination']);
        $this->assertSame('access-one', app(McpCredentialVault::class)->accessToken($connection));
        $this->assertSame('oauth', $connection->server()->value('auth_mode'));

        $this->expectException(\RuntimeException::class);
        app(McpOAuthService::class)->callback(
            (string) $query['state'],
            'code-one',
            $owner,
            'https://auth.example.test/tenant',
        );
    }

    public function test_expired_oauth_credential_is_rotated_under_the_vault_lock(): void
    {
        $http = new OAuthFakeHttpClient;
        $this->app->instance(SafeHttpClientContract::class, $http);
        config()->set('connector-mcp.oauth.client_metadata_url', 'https://askmydocs.example/.well-known/mcp-client.json');
        $this->metadataResponses($http);
        $http->respond('POST_FORM', 'https://auth.example.test/tenant/token', 200, [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ]);

        [$connection] = $this->personalConnection();
        $connection->server()->update(['auth_mode' => 'oauth']);
        app(McpCredentialVault::class)->put(
            connection: $connection,
            accessToken: 'expired-access',
            refreshToken: 'old-refresh',
            expiresAt: now()->subMinute(),
            issuer: 'https://auth.example.test/tenant',
            resource: 'https://mcp.example.test/mcp',
        );

        app(McpOAuthService::class)->refreshIfNeeded($connection->fresh('server'));

        $credential = app(McpCredentialVault::class)->oauthCredential($connection);
        $this->assertSame('rotated-access', $credential?->access_token);
        $this->assertSame('rotated-refresh', $credential?->refresh_token);
        $this->assertSame(1, $credential?->rotation_version);
    }

    private function metadataResponses(OAuthFakeHttpClient $http): void
    {
        $http->respond('GET', 'https://mcp.example.test/.well-known/oauth-protected-resource/mcp', 200, [
            'resource' => 'https://mcp.example.test/mcp',
            'authorization_servers' => ['https://auth.example.test/tenant'],
            'scopes_supported' => ['files:read'],
        ]);
        $http->respond('GET', 'https://auth.example.test/.well-known/oauth-authorization-server/tenant', 200, [
            'issuer' => 'https://auth.example.test/tenant',
            'authorization_endpoint' => 'https://auth.example.test/tenant/authorize',
            'token_endpoint' => 'https://auth.example.test/tenant/token',
            'code_challenge_methods_supported' => ['S256'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /** @return array{McpConnection,TestUser} */
    private function personalConnection(): array
    {
        app(TenantContext::class)->set('acme');
        $owner = TestUser::query()->create(['name' => 'Marco']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Files MCP',
            'catalog_scope' => 'personal',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://mcp.example.test/mcp',
        ]);
        $connection = McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'personal',
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'label' => 'Files',
        ]);

        return [$connection->load('server'), $owner];
    }
}

final class OAuthFakeHttpClient implements SafeHttpClientContract
{
    /** @var array<string,Response> */
    private array $responses = [];

    /** @param array<string,mixed> $payload */
    public function respond(string $method, string $url, int $status, array $payload): void
    {
        $this->responses[$method.' '.$url] = new Response(new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload, JSON_THROW_ON_ERROR),
        ));
    }

    public function get(string $url, array $headers = [], bool $personal = true): Response
    {
        return $this->response('GET', $url);
    }

    public function postForm(string $url, array $form, array $headers = [], bool $personal = true): Response
    {
        return $this->response('POST_FORM', $url);
    }

    public function postJson(string $url, array $json, array $headers = [], bool $personal = true): Response
    {
        return $this->response('POST_JSON', $url);
    }

    private function response(string $method, string $url): Response
    {
        return $this->responses[$method.' '.$url]
            ?? new Response(new PsrResponse(404, ['Content-Type' => 'application/json'], '{}'));
    }
}
