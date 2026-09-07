<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\URL;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Contracts\SafeHttpClientContract;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;

final class HttpApiIsolationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.url', 'https://askmydocs.example');
        $app['config']->set('connector-mcp.enabled', true);
        $app['config']->set('connector-mcp.routes.middleware', ['web']);
        $app['config']->set('connector-mcp.routes.admin_ability', null);
        $app['config']->set('connector-mcp.http.internal_endpoint_allowlist', ['mcp.example.test']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('https://askmydocs.example');
        URL::forceScheme('https');
    }

    public function test_personal_and_admin_catalogs_expose_only_their_connection_modes(): void
    {
        app(TenantContext::class)->set('acme');
        $marco = TestUser::query()->create(['name' => 'Marco']);
        $other = TestUser::query()->create(['name' => 'Other']);
        $server = McpServerDefinition::query()->create([
            'name' => 'Approved',
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://mcp.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $shared = $this->connection($server, 'shared', null, 'Shared');
        $mine = $this->connection($server, 'personal', $marco, 'Mine');
        $this->connection($server, 'personal', $other, 'Hidden');

        $personal = $this->actingAs($marco)->getJson('/api/me/connected-apps/mcp');
        $personal->assertOk()->assertJsonCount(1)->assertJsonPath('0.public_id', $mine->public_id);

        $admin = $this->actingAs($marco)->getJson('/api/admin/connectors/mcp');
        $admin->assertOk()->assertJsonCount(1)->assertJsonPath('0.public_id', $shared->public_id);
    }

    public function test_metadata_document_is_public_but_fail_closed_with_the_package_flag(): void
    {
        $this->getJson('/.well-known/mcp-client.json')
            ->assertOk()
            ->assertJsonPath('client_id', 'https://askmydocs.example/.well-known/mcp-client.json')
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');

        config()->set('connector-mcp.oauth.enabled', false);
        $this->getJson('/.well-known/mcp-client.json')->assertNotFound();

        config()->set('connector-mcp.oauth.enabled', true);
        config()->set('connector-mcp.enabled', false);
        $this->getJson('/.well-known/mcp-client.json')->assertNotFound();
    }

    public function test_admin_can_choose_oauth_during_connection_creation(): void
    {
        app(TenantContext::class)->set('acme');
        $http = new OnboardingOAuthHttpClient;
        $this->app->instance(SafeHttpClientContract::class, $http);
        $http->respond('GET', 'https://mcp.example.test/.well-known/oauth-protected-resource/mcp', [
            'resource' => 'https://mcp.example.test/mcp',
            'authorization_servers' => ['https://auth.example.test'],
            'scopes_supported' => ['orders:read'],
        ]);
        $http->respond('GET', 'https://auth.example.test/.well-known/oauth-authorization-server', [
            'issuer' => 'https://auth.example.test',
            'authorization_endpoint' => 'https://auth.example.test/authorize',
            'token_endpoint' => 'https://auth.example.test/token',
            'code_challenge_methods_supported' => ['S256'],
            'client_id_metadata_document_supported' => true,
        ]);
        $admin = TestUser::query()->create(['name' => 'Admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin/connectors/mcp', [
            'name' => 'Orders MCP',
            'endpoint' => 'https://mcp.example.test/mcp',
            'auth_method' => 'oauth',
            'ui_destination' => '/app/admin/connectors',
        ]);

        $response->assertCreated()
            ->assertJsonPath('connection.server.auth_mode', 'oauth')
            ->assertJsonPath('connection.status', McpConnection::STATUS_PENDING)
            ->assertJsonPath('authorization_required', true)
            ->assertJsonPath('next_action.type', 'oauth_redirect');
        $authorizationUrl = (string) $response->json('next_action.authorization_url');
        $this->assertStringStartsWith('https://auth.example.test/authorize?', $authorizationUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $authorizationUrl);
        $this->assertDatabaseCount('mcp_connector_credentials', 0);
    }

    public function test_personal_catalog_connection_inherits_the_approved_servers_oauth_mode(): void
    {
        app(TenantContext::class)->set('acme');
        $http = new OnboardingOAuthHttpClient;
        $this->app->instance(SafeHttpClientContract::class, $http);
        $http->respond('GET', 'https://8.8.8.8/.well-known/oauth-protected-resource/mcp', [
            'resource' => 'https://8.8.8.8/mcp',
            'authorization_servers' => ['https://auth.example.test'],
        ]);
        $http->respond('GET', 'https://auth.example.test/.well-known/oauth-authorization-server', [
            'issuer' => 'https://auth.example.test',
            'authorization_endpoint' => 'https://auth.example.test/authorize',
            'token_endpoint' => 'https://auth.example.test/token',
            'code_challenge_methods_supported' => ['S256'],
            'client_id_metadata_document_supported' => true,
        ]);
        $server = McpServerDefinition::query()->create([
            'name' => 'Approved OAuth MCP',
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'oauth',
            'endpoint' => 'https://8.8.8.8/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $user = TestUser::query()->create(['name' => 'Marco']);

        $this->actingAs($user)->postJson('/api/me/connected-apps/mcp', [
            'server_id' => $server->getKey(),
            'ui_destination' => '/app/connected-apps',
        ])->assertCreated()
            ->assertJsonPath('connection.server.auth_mode', 'oauth')
            ->assertJsonPath('authorization_required', true)
            ->assertJsonPath('next_action.type', 'oauth_redirect');

        $this->actingAs($user)->postJson('/api/me/connected-apps/mcp', [
            'server_id' => $server->getKey(),
            'auth_method' => 'none',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('auth_method');

        $this->assertSame(1, McpConnection::query()->where('mode', 'personal')->count());
    }

    private function connection(McpServerDefinition $server, string $mode, ?TestUser $owner, string $label): McpConnection
    {
        return McpConnection::query()->create([
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => $mode,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'label' => $label,
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
    }
}

final class OnboardingOAuthHttpClient implements SafeHttpClientContract
{
    /** @var array<string,Response> */
    private array $responses = [];

    /** @param array<string,mixed> $payload */
    public function respond(string $method, string $url, array $payload): void
    {
        $this->responses[$method.' '.$url] = new Response(new PsrResponse(
            200,
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
