<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Contracts\SafeHttpClientContract;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpCredential;
use Padosoft\AskMyDocsConnectorMcp\Models\McpOAuthAttempt;
use Padosoft\AskMyDocsConnectorMcp\Models\McpOAuthClient;
use Padosoft\AskMyDocsConnectorMcp\Support\McpOAuthStart;

final readonly class McpOAuthService
{
    public function __construct(
        private TenantContext $tenantContext,
        private SafeHttpClientContract $http,
        private McpCredentialVault $vault,
        private McpConnectionManager $connections,
    ) {}

    /** @param list<string> $scopes */
    public function begin(
        McpConnection $connection,
        Model $owner,
        array $scopes = [],
        ?string $wwwAuthenticate = null,
        ?string $uiDestination = null,
    ): McpOAuthStart {
        if (! (bool) config('connector-mcp.oauth.enabled', true)) {
            throw new \RuntimeException('MCP OAuth connections are disabled.');
        }
        if ($connection->isPersonal()) {
            $this->connections->assertOwner($connection, $owner);
        }
        $connection->loadMissing('server');
        $resource = (string) $connection->server->endpoint;
        $metadata = $this->discoverAuthorizationMetadata($resource, $wwwAuthenticate, $connection->isPersonal());
        $client = $this->resolveClient($metadata, $connection->isPersonal());

        $state = Str::random(64);
        $verifier = $this->base64Url(random_bytes(64));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $expiresAt = now()->addSeconds((int) config('connector-mcp.oauth.state_ttl_seconds', 600));
        $challengedScopes = $this->scopesFromChallenge($wwwAuthenticate);
        $effectiveScopes = $scopes !== []
            ? array_values(array_unique(array_merge($scopes, $challengedScopes)))
            : ($challengedScopes !== [] ? $challengedScopes : $metadata['scopes_supported']);
        if ($effectiveScopes === []) {
            $effectiveScopes = array_values(array_filter((array) config('connector-mcp.oauth.default_scopes', []), 'is_string'));
        }
        $redirectUri = $this->callbackUri();

        McpOAuthAttempt::query()->create([
            'tenant_id' => $this->tenantContext->current(),
            'mcp_connector_connection_id' => $connection->getKey(),
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'state_hash' => hash('sha256', $state),
            'pkce_verifier' => $verifier,
            'issuer' => $metadata['issuer'],
            'resource' => $resource,
            'authorization_endpoint' => $metadata['authorization_endpoint'],
            'token_endpoint' => $metadata['token_endpoint'],
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'authorization_response_iss_parameter_supported' => $metadata['authorization_response_iss_parameter_supported'],
            'scopes_json' => $effectiveScopes,
            'ui_destination' => $this->safeDestination($uiDestination),
            'expires_at' => $expiresAt,
        ]);

        $query = [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => $resource,
        ];
        if ($effectiveScopes !== []) {
            $query['scope'] = implode(' ', array_map('strval', $effectiveScopes));
        }

        return new McpOAuthStart(
            authorizationUrl: $metadata['authorization_endpoint'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    /** @return array{connection:McpConnection,destination:string,status:string} */
    public function callback(string $state, string $code, Model $owner, ?string $issuer = null): array
    {
        if ($state === '' || $code === '') {
            throw new \InvalidArgumentException('OAuth callback is missing state or code.');
        }

        [$attempt, $connection] = $this->consumeAttempt($state, $owner, $issuer);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUri(),
            'client_id' => $attempt->client_id,
            'code_verifier' => $attempt->pkce_verifier,
            'resource' => $attempt->resource,
        ];
        if (is_string($attempt->client_secret) && $attempt->client_secret !== '') {
            $form['client_secret'] = $attempt->client_secret;
        }
        $response = $this->http->postForm(
            $attempt->token_endpoint,
            $form,
            ['Accept' => 'application/json'],
            $connection->isPersonal(),
        );
        if (! $response->successful() || ! is_array($response->json())) {
            return $this->failedCallback($connection, $attempt, 'token_exchange_failed');
        }
        $tokens = $response->json();
        $accessToken = $tokens['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            return $this->failedCallback($connection, $attempt, 'invalid_token_response');
        }
        $tokenType = is_string($tokens['token_type'] ?? null) ? $tokens['token_type'] : 'Bearer';
        if (strcasecmp($tokenType, 'Bearer') !== 0) {
            return $this->failedCallback($connection, $attempt, 'unsupported_token_type');
        }
        $this->vault->put(
            connection: $connection,
            accessToken: $accessToken,
            refreshToken: is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : null,
            expiresAt: isset($tokens['expires_in']) ? now()->addSeconds(max(0, (int) $tokens['expires_in'])) : null,
            scopes: $this->tokenScopes($tokens, $attempt),
            tokenType: 'Bearer',
            issuer: $attempt->issuer,
            resource: $attempt->resource,
        );
        $connection->forceFill([
            'status' => McpConnection::STATUS_PENDING,
            'last_authorized_at' => now(),
            'granted_scopes_json' => is_string($tokens['scope'] ?? null)
                ? preg_split('/\s+/', trim($tokens['scope']), -1, PREG_SPLIT_NO_EMPTY)
                : $attempt->scopes_json,
            'error_json' => null,
        ])->save();
        $connection->server()->update(['auth_mode' => 'oauth']);

        return [
            'connection' => $connection,
            'destination' => $attempt->ui_destination ?: '/app/connected-apps',
            'status' => 'authorized',
        ];
    }

    /** @return array{connection:McpConnection,destination:string,status:string} */
    public function deniedCallback(string $state, string $error, Model $owner, ?string $issuer = null): array
    {
        if ($state === '') {
            throw new \InvalidArgumentException('OAuth callback is missing state.');
        }
        [, $connection, $destination] = $this->consumeAttemptWithDestination($state, $owner, $issuer);
        $code = preg_match('/^[a-z0-9_.-]{1,64}$/i', $error) === 1 ? strtolower($error) : 'authorization_failed';
        $status = $code === 'access_denied' ? 'oauth_denied' : 'oauth_failed';
        $this->markAuthorizationFailure($connection, $code);

        return compact('connection', 'destination', 'status');
    }

    public function refreshIfNeeded(McpConnection $connection): void
    {
        $credential = $this->vault->oauthCredential($connection);
        if ($credential === null || ! $credential->isExpired()) {
            return;
        }
        if (! is_string($credential->refresh_token) || $credential->refresh_token === '') {
            $this->markReauthorizationRequired($connection, 'refresh_token_unavailable');
            throw new \RuntimeException('The MCP OAuth connection requires reauthorization.');
        }

        $connection->loadMissing('server');
        $resource = is_string($credential->resource) && $credential->resource !== ''
            ? $credential->resource
            : (string) $connection->server->endpoint;
        $metadata = $this->discoverAuthorizationMetadata($resource, null, $connection->isPersonal());
        if (is_string($credential->issuer) && $credential->issuer !== '' && ! hash_equals($credential->issuer, $metadata['issuer'])) {
            $this->markReauthorizationRequired($connection, 'issuer_changed');
            throw new \RuntimeException('OAuth issuer changed; reauthorization is required.');
        }
        $client = $this->resolveClient($metadata, $connection->isPersonal());

        $reauthorizationCode = null;
        try {
            $this->vault->rotateExpired($connection, function (McpCredential $locked) use ($resource, $metadata, $client, $connection, &$reauthorizationCode): array {
                if (! is_string($locked->refresh_token) || $locked->refresh_token === '') {
                    $reauthorizationCode = 'refresh_token_unavailable';
                    throw new \RuntimeException('The MCP OAuth refresh token is unavailable.');
                }
                $form = [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $locked->refresh_token,
                    'client_id' => $client['client_id'],
                    'resource' => $resource,
                ];
                if ($client['client_secret'] !== null && $client['client_secret'] !== '') {
                    $form['client_secret'] = $client['client_secret'];
                }
                $response = $this->http->postForm($metadata['token_endpoint'], $form, ['Accept' => 'application/json'], $connection->isPersonal());
                $payload = $response->json();
                if (! $response->successful() || ! is_array($payload)) {
                    $oauthError = is_array($payload) && is_string($payload['error'] ?? null) ? $payload['error'] : null;
                    if (in_array($response->status(), [400, 401], true) || $oauthError === 'invalid_grant') {
                        $reauthorizationCode = $oauthError ?? 'refresh_rejected';
                    }
                    throw new \RuntimeException("OAuth refresh failed with status {$response->status()}.");
                }
                $tokenType = is_string($payload['token_type'] ?? null) ? $payload['token_type'] : 'Bearer';
                if (strcasecmp($tokenType, 'Bearer') !== 0) {
                    $reauthorizationCode = 'unsupported_token_type';
                    throw new \RuntimeException('OAuth refresh returned an unsupported token type.');
                }
                $payload['token_type'] = 'Bearer';

                return $payload;
            });
        } catch (\Throwable $exception) {
            if ($reauthorizationCode !== null) {
                $this->markReauthorizationRequired($connection, $reauthorizationCode);
            }

            throw $exception;
        }
    }

    public function requireReauthorization(McpConnection $connection, string $challenge, int $status): void
    {
        $connection->loadMissing('server');
        $scopes = $this->scopesFromChallenge($challenge);
        $oauthError = $this->challengeParameter($challenge, 'error');
        $code = $oauthError === 'insufficient_scope' || $status === 403
            ? 'insufficient_scope'
            : 'authorization_required';
        $connection->server->forceFill(['oauth_metadata_json' => [
            'www_authenticate' => mb_substr($challenge, 0, 4096),
            'challenged_at' => now()->toIso8601String(),
        ]])->save();
        $connection->forceFill([
            'status' => McpConnection::STATUS_REAUTHORIZATION_REQUIRED,
            'error_json' => [
                'phase' => 'oauth_challenge',
                'code' => $code,
                'http_status' => $status,
                'required_scopes' => $scopes,
            ],
        ])->save();
    }

    /** @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,registration_endpoint:?string,scopes_supported:list<string>,client_id_metadata_document_supported:bool,authorization_response_iss_parameter_supported:bool} */
    private function discoverAuthorizationMetadata(string $resource, ?string $challenge, bool $personal): array
    {
        $protected = null;
        foreach ($this->protectedResourceMetadataUrls($resource, $challenge) as $resourceMetadataUrl) {
            $response = $this->http->get($resourceMetadataUrl, ['Accept' => 'application/json'], $personal);
            $payload = $response->json();
            if ($response->successful() && is_array($payload)) {
                $protected = $payload;
                break;
            }
        }
        if (! is_array($protected)) {
            throw new \RuntimeException('OAuth protected resource metadata discovery failed.');
        }
        $advertisedResource = $protected['resource'] ?? null;
        if (is_string($advertisedResource) && rtrim($advertisedResource, '/') !== rtrim($resource, '/')) {
            throw new \RuntimeException('Protected resource metadata does not match the MCP endpoint.');
        }
        $servers = $protected['authorization_servers'] ?? [];
        $issuer = is_array($servers) && is_string($servers[0] ?? null)
            ? $servers[0]
            : $this->challengeParameter($challenge, 'authorization_uri');
        if (! is_string($issuer) || $issuer === '') {
            throw new \RuntimeException('OAuth discovery did not advertise an authorization server.');
        }
        $issuer = rtrim($issuer, '/');
        $this->assertSecureOAuthUrl($issuer, 'issuer');
        $authorization = null;
        foreach ($this->authorizationMetadataUrls($issuer) as $metadataUrl) {
            $response = $this->http->get($metadataUrl, ['Accept' => 'application/json'], $personal);
            $payload = $response->json();
            if ($response->successful() && is_array($payload)) {
                $authorization = $payload;
                break;
            }
        }
        if (! is_array($authorization)) {
            throw new \RuntimeException('OAuth authorization server metadata discovery failed.');
        }
        if (($authorization['issuer'] ?? null) !== $issuer) {
            throw new \RuntimeException('Authorization server issuer mismatch.');
        }
        $methods = $authorization['code_challenge_methods_supported'] ?? [];
        if (! is_array($methods) || ! in_array('S256', $methods, true)) {
            throw new \RuntimeException('Authorization server does not advertise PKCE S256.');
        }
        foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
            if (! is_string($authorization[$required] ?? null)) {
                throw new \RuntimeException("Authorization server metadata omitted {$required}.");
            }
            $this->assertSecureOAuthUrl($authorization[$required], $required);
        }
        if (is_string($authorization['registration_endpoint'] ?? null)) {
            $this->assertSecureOAuthUrl($authorization['registration_endpoint'], 'registration_endpoint');
        }

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $authorization['authorization_endpoint'],
            'token_endpoint' => $authorization['token_endpoint'],
            'registration_endpoint' => is_string($authorization['registration_endpoint'] ?? null) ? $authorization['registration_endpoint'] : null,
            'scopes_supported' => array_values(array_filter((array) ($protected['scopes_supported'] ?? []), 'is_string')),
            'client_id_metadata_document_supported' => ($authorization['client_id_metadata_document_supported'] ?? false) === true,
            'authorization_response_iss_parameter_supported' => ($authorization['authorization_response_iss_parameter_supported'] ?? false) === true,
        ];
    }

    /**
     * @param  array{issuer:string,authorization_endpoint:string,token_endpoint:string,registration_endpoint:?string,scopes_supported:list<string>,client_id_metadata_document_supported:bool,authorization_response_iss_parameter_supported:bool}  $metadata
     * @return array{client_id:string,client_secret:?string}
     */
    private function resolveClient(array $metadata, bool $personal): array
    {
        $configuredClients = (array) config('connector-mcp.oauth.clients', []);
        $configured = $configuredClients[$metadata['issuer']] ?? null;
        if (is_array($configured) && is_string($configured['client_id'] ?? null) && $configured['client_id'] !== '') {
            return [
                'client_id' => $configured['client_id'],
                'client_secret' => is_string($configured['client_secret'] ?? null) && $configured['client_secret'] !== ''
                    ? $configured['client_secret']
                    : null,
            ];
        }

        $stored = McpOAuthClient::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('issuer_hash', hash('sha256', $metadata['issuer']))
            ->first();
        if ($stored !== null) {
            return ['client_id' => (string) $stored->client_id, 'client_secret' => $stored->client_secret];
        }

        $cimd = $this->clientMetadataUrl();
        if ($metadata['client_id_metadata_document_supported'] && $cimd !== null) {
            return ['client_id' => $cimd, 'client_secret' => null];
        }
        if ($metadata['registration_endpoint'] === null) {
            throw new \RuntimeException('No pre-registered/CIMD OAuth client and DCR is unavailable.');
        }
        $registration = [
            'client_name' => (string) config('connector-mcp.oauth.client_name', 'AskMyDocs'),
            'redirect_uris' => [$this->callbackUri()],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'application_type' => 'web',
        ];
        $response = $this->http->postJson($metadata['registration_endpoint'], $registration, ['Accept' => 'application/json'], $personal);
        $payload = $this->json($response, 'dynamic client registration');
        if (! is_string($payload['client_id'] ?? null)) {
            throw new \RuntimeException('Dynamic client registration omitted client_id.');
        }
        $stored = McpOAuthClient::query()->create([
            'tenant_id' => $this->tenantContext->current(),
            'strategy' => 'dcr',
            'issuer' => $metadata['issuer'],
            'issuer_hash' => hash('sha256', $metadata['issuer']),
            'client_id' => $payload['client_id'],
            'client_secret' => is_string($payload['client_secret'] ?? null) ? $payload['client_secret'] : null,
            'registration_metadata_json' => $payload,
        ]);

        return ['client_id' => (string) $stored->client_id, 'client_secret' => $stored->client_secret];
    }

    /** @return array<string,mixed> */
    private function json(Response $response, string $label): array
    {
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            throw new \RuntimeException("OAuth {$label} request failed with status {$response->status()}.");
        }

        return $payload;
    }

    private function challengeParameter(?string $challenge, string $name): ?string
    {
        if ($challenge === null
            || preg_match('/(?:^|[\s,])'.preg_quote($name, '/').'\s*=\s*(?:"([^"]*)"|([^,\s]+))/i', $challenge, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $value = $matches[1] ?? $matches[2] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private function scopesFromChallenge(?string $challenge): array
    {
        $scope = $this->challengeParameter($challenge, 'scope');
        if ($scope === null) {
            return [];
        }
        $values = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($values) ? array_values(array_unique($values)) : [];
    }

    /** @return list<string> */
    private function protectedResourceMetadataUrls(string $resource, ?string $challenge): array
    {
        $challenged = $this->challengeParameter($challenge, 'resource_metadata');
        if ($challenged !== null) {
            return [$challenged];
        }
        $parts = parse_url($resource);
        $origin = $this->origin($resource);
        $path = isset($parts['path']) ? '/'.ltrim((string) $parts['path'], '/') : '';
        $urls = [];
        if ($path !== '' && $path !== '/') {
            $urls[] = rtrim($origin, '/').'/.well-known/oauth-protected-resource'.$path;
        }
        $urls[] = rtrim($origin, '/').'/.well-known/oauth-protected-resource';

        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    private function authorizationMetadataUrls(string $issuer): array
    {
        $parts = parse_url($issuer);
        $origin = $this->origin($issuer);
        $path = isset($parts['path']) ? '/'.ltrim((string) $parts['path'], '/') : '';
        if ($path === '' || $path === '/') {
            return [
                rtrim($origin, '/').'/.well-known/oauth-authorization-server',
                rtrim($origin, '/').'/.well-known/openid-configuration',
            ];
        }

        return [
            rtrim($origin, '/').'/.well-known/oauth-authorization-server'.$path,
            rtrim($origin, '/').'/.well-known/openid-configuration'.$path,
            rtrim($issuer, '/').'/.well-known/openid-configuration',
        ];
    }

    private function isValidClientMetadataUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && isset($parts['path'])
            && $parts['path'] !== ''
            && $parts['path'] !== '/';
    }

    public function clientMetadataUrl(): ?string
    {
        $configured = config('connector-mcp.oauth.client_metadata_url');
        $url = is_string($configured) && $configured !== ''
            ? $configured
            : url((string) config('connector-mcp.oauth.client_metadata_path', '/.well-known/mcp-client.json'));

        return $this->isValidClientMetadataUrl($url) ? $url : null;
    }

    private function callbackUri(): string
    {
        return url((string) config('connector-mcp.oauth.callback_path', '/api/connectors/mcp/oauth/callback'));
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return (string) ($parts['scheme'] ?? '').'://'.(string) ($parts['host'] ?? '').$port;
    }

    private function safeDestination(?string $destination): ?string
    {
        return is_string($destination) && str_starts_with($destination, '/') && ! str_starts_with($destination, '//')
            ? $destination
            : null;
    }

    /** @return array{McpOAuthAttempt,McpConnection} */
    private function consumeAttempt(string $state, Model $owner, ?string $issuer): array
    {
        [$attempt, $connection] = $this->consumeAttemptWithDestination($state, $owner, $issuer);

        return [$attempt, $connection];
    }

    /** @return array{McpOAuthAttempt,McpConnection,string} */
    private function consumeAttemptWithDestination(string $state, Model $owner, ?string $issuer): array
    {
        return DB::transaction(function () use ($state, $owner, $issuer): array {
            $attemptId = DB::table('mcp_connector_oauth_attempts')
                ->where('tenant_id', $this->tenantContext->current())
                ->where('state_hash', hash('sha256', $state))
                ->lockForUpdate()
                ->value('id');
            if (! is_int($attemptId)) {
                throw new ModelNotFoundException;
            }
            $attempt = McpOAuthAttempt::query()->findOrFail($attemptId);
            if ($attempt->consumed_at !== null || $attempt->expires_at->isPast()) {
                throw new \RuntimeException('OAuth state has expired or was already consumed.');
            }
            if ($attempt->owner_type !== $owner->getMorphClass() || (string) $attempt->owner_id !== (string) $owner->getKey()) {
                throw new AuthorizationException('OAuth state does not belong to the authenticated user.');
            }
            if ($issuer !== null && ! hash_equals($attempt->issuer, $issuer)) {
                throw new AuthorizationException('OAuth authorization response issuer mismatch.');
            }
            if ($attempt->authorization_response_iss_parameter_supported && $issuer === null) {
                throw new AuthorizationException('OAuth authorization response omitted the required issuer.');
            }
            $connection = $attempt->connection()->with('server')->firstOrFail();
            if ((string) $connection->tenant_id !== (string) $attempt->tenant_id
                || ! hash_equals(rtrim((string) $connection->server->endpoint, '/'), rtrim($attempt->resource, '/'))) {
                throw new AuthorizationException('OAuth state resource binding no longer matches the MCP connection.');
            }
            $attempt->forceFill(['consumed_at' => now()])->save();

            return [$attempt, $connection, $attempt->ui_destination ?: '/app/connected-apps'];
        }, 3);
    }

    /** @return array{connection:McpConnection,destination:string,status:string} */
    private function failedCallback(McpConnection $connection, McpOAuthAttempt $attempt, string $code): array
    {
        $this->markAuthorizationFailure($connection, $code);

        return [
            'connection' => $connection,
            'destination' => $attempt->ui_destination ?: '/app/connected-apps',
            'status' => 'oauth_failed',
        ];
    }

    private function markAuthorizationFailure(McpConnection $connection, string $code): void
    {
        $changes = ['error_json' => ['phase' => 'oauth_authorization', 'code' => $code]];
        if ($connection->status !== McpConnection::STATUS_ACTIVE) {
            $changes['status'] = McpConnection::STATUS_ERRORED;
        }
        $connection->forceFill($changes)->save();
    }

    private function markReauthorizationRequired(McpConnection $connection, string $code): void
    {
        $connection->forceFill([
            'status' => McpConnection::STATUS_REAUTHORIZATION_REQUIRED,
            'error_json' => ['phase' => 'oauth_refresh', 'code' => $code],
        ])->save();
    }

    private function assertSecureOAuthUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localHttp = $scheme === 'http'
            && (bool) config('connector-mcp.oauth.allow_insecure_local', false)
            && ($host === 'localhost' || $host === '::1' || str_starts_with($host, '127.'));
        if (! is_array($parts) || ($scheme !== 'https' && ! $localHttp) || $host === '') {
            throw new \RuntimeException("OAuth {$label} must be an absolute HTTPS URL.");
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<string,mixed>  $tokens
     * @return list<string>
     */
    private function tokenScopes(array $tokens, McpOAuthAttempt $attempt): array
    {
        if (is_string($tokens['scope'] ?? null)) {
            $scopes = preg_split('/\s+/', trim($tokens['scope']), -1, PREG_SPLIT_NO_EMPTY);

            return is_array($scopes) ? $scopes : [];
        }

        return is_array($attempt->scopes_json) ? array_values(array_filter($attempt->scopes_json, 'is_string')) : [];
    }
}
