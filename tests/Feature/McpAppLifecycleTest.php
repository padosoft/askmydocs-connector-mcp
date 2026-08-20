<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpAppInstance;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpAppInstanceService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolExecutor;
use Padosoft\AskMyDocsConnectorMcp\Tests\Support\TestUser;
use Padosoft\AskMyDocsConnectorMcp\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

final class McpAppLifecycleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('connector-mcp.enabled', true);
        $app['config']->set('connector-mcp.routes.middleware', ['web']);
        $app['config']->set('connector-mcp.apps.sandbox_origin', 'https://mcp-sandbox.example.test');
        $app['config']->set('connector-mcp.apps.host_origins', ['https://askmydocs.example.test']);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_app_payload_is_encrypted_and_resolved_only_for_its_actor_and_conversation(): void
    {
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $other = TestUser::query()->create(['name' => 'Other']);
        $tool = $this->tool();
        McpClient::useTransportResolver(static fn () => new McpAppTransport);

        $outcome = app(McpToolExecutor::class)->invoke(
            $tool->local_name,
            ['secretInput' => 'do-not-store-in-chat'],
            $actor,
            'conversation-1',
        );
        $app = $outcome->artifact?->app;

        $this->assertIsArray($app);
        $this->assertSame('ui://reports/result.html', $app['resource_uri']);
        $raw = (array) DB::table('mcp_connector_app_instances')->first();
        $this->assertStringNotContainsString('ui://reports/result.html', (string) $raw['resource_uri']);
        $this->assertStringNotContainsString('do-not-store-in-chat', (string) $raw['tool_input']);
        $this->assertStringNotContainsString('widget-only-secret', (string) $raw['tool_result']);

        $url = '/api/conversations/mcp/apps/'.$app['id'].'?conversation_id=conversation-1';
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $this->actingAs($actor)->getJson(
            '/api/conversations/mcp/apps/'.$app['id'].'?conversation_id=wrong',
        )->assertNotFound();

        $this->actingAs($actor)->getJson($url)
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('sandbox_url', 'https://mcp-sandbox.example.test/mcp-apps/sandbox')
            ->assertJsonPath('mime_type', 'text/html;profile=mcp-app')
            ->assertJsonPath('tool_input.secretInput', 'do-not-store-in-chat')
            ->assertJsonPath('tool_result._meta.widgetSecret', 'widget-only-secret')
            ->assertJsonPath('csp.connectDomains.0', 'https://api.widgets.example.test')
            ->assertJsonMissing(['https://askmydocs.example.test'])
            ->assertJsonMissing(['https://mcp-sandbox.example.test']);

        $this->assertStringContainsString('<main id="app">Report</main>', (string) data_get(
            $this->actingAs($actor)->getJson($url)->json(),
            'html',
        ));
    }

    public function test_app_can_call_only_enabled_tools_visible_to_the_app(): void
    {
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $tool = $this->tool();
        $transport = new McpAppTransport;
        McpClient::useTransportResolver(static fn () => $transport);
        $outcome = app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-2');
        $appId = (string) data_get($outcome->artifact?->app, 'id');

        McpConnectionTool::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $tool->mcp_connector_connection_id,
            'remote_name' => 'reports.refresh',
            'local_name' => 'mcp_reports_refresh',
            'input_schema_json' => ['type' => 'object'],
            'meta_json' => ['ui' => ['visibility' => ['app']]],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);
        McpConnectionTool::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $tool->mcp_connector_connection_id,
            'remote_name' => 'reports.hidden',
            'local_name' => 'mcp_reports_hidden',
            'input_schema_json' => ['type' => 'object'],
            'meta_json' => ['ui' => ['visibility' => ['model']]],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);

        $endpoint = '/api/conversations/mcp/apps/'.$appId.'/tools/call';
        $this->actingAs($actor)->postJson($endpoint, [
            'conversation_id' => 'conversation-2',
            'name' => 'reports.refresh',
            'arguments' => ['page' => 2],
        ])->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.structuredContent.page', 2);

        $this->actingAs($actor)->postJson($endpoint, [
            'conversation_id' => 'conversation-2',
            'name' => 'reports.hidden',
            'arguments' => [],
        ])->assertNotFound();

        $this->assertSame('reports.refresh', $transport->lastToolName);
    }

    public function test_mismatched_result_resource_is_not_promoted_to_an_app(): void
    {
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $tool = $this->tool();
        $transport = new McpAppTransport;
        $transport->resultResourceUri = 'ui://malicious/other.html';
        McpClient::useTransportResolver(static fn () => $transport);

        $outcome = app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-3');

        $this->assertNull($outcome->artifact?->app);
        $this->assertSame(0, DB::table('mcp_connector_app_instances')->count());
    }

    public function test_sandbox_proxy_is_public_static_and_deny_by_default(): void
    {
        config()->set('connector-mcp.apps.allowed_permissions', ['camera', 'clipboardWrite']);
        $response = $this->get('/mcp-apps/sandbox');

        $response->assertOk();
        $this->assertSame('1', $response->headers->get('X-AskMyDocs-MCP-App-Sandbox'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('frame-ancestors https://askmydocs.example.test', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame(
            'camera=(*), microphone=(), geolocation=(), clipboard-write=(*)',
            $response->headers->get('Permissions-Policy'),
        );
        $response->assertSee('ui/notifications/sandbox-proxy-ready', false);
        $response->assertSee("setAttribute('sandbox', 'allow-scripts')", false);
    }

    public function test_advanced_model_context_is_opt_in_encrypted_and_scoped_to_the_app(): void
    {
        config()->set('connector-mcp.apps.advanced_enabled', true);
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $tool = $this->tool();
        McpClient::useTransportResolver(static fn () => new McpAppTransport);
        $outcome = app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-context');
        $appId = (string) data_get($outcome->artifact?->app, 'id');

        $this->actingAs($actor)->getJson(
            '/api/conversations/mcp/apps/'.$appId.'?conversation_id=conversation-context',
        )->assertOk()->assertJsonPath('advanced_enabled', true);

        $this->actingAs($actor)->putJson('/api/conversations/mcp/apps/'.$appId.'/model-context', [
            'conversation_id' => 'conversation-context',
            'content' => [['type' => 'text', 'text' => 'The user selected region Europe.']],
            'structuredContent' => ['region' => 'EU'],
        ])->assertOk();

        $raw = (string) DB::table('mcp_connector_app_instances')->where('public_id', $appId)->value('model_context');
        $this->assertStringNotContainsString('selected region Europe', $raw);
        $instance = McpAppInstance::query()->where('public_id', $appId)->firstOrFail();
        $prompt = app(McpAppInstanceService::class)->promptContext($instance);
        $this->assertStringContainsString('selected region Europe', (string) $prompt);
        $this->assertStringContainsString('"region":"EU"', (string) $prompt);
    }

    public function test_advanced_downloads_materialize_embedded_and_linked_resources_as_signed_artifacts(): void
    {
        config()->set('connector-mcp.apps.advanced_enabled', true);
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $tool = $this->tool();
        McpClient::useTransportResolver(static fn () => new McpAppTransport);
        $outcome = app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-download');
        $appId = (string) data_get($outcome->artifact?->app, 'id');

        $response = $this->actingAs($actor)->postJson('/api/conversations/mcp/apps/'.$appId.'/downloads', [
            'conversation_id' => 'conversation-download',
            'contents' => [
                [
                    'type' => 'resource',
                    'resource' => [
                        'uri' => 'file://summary.txt',
                        'mimeType' => 'text/plain',
                        'text' => 'Private generated summary.',
                    ],
                ],
                [
                    'type' => 'resource_link',
                    'uri' => 'docs://exports/report.csv',
                    'name' => 'report.csv',
                ],
            ],
        ])->assertOk();

        $this->assertCount(2, $response->json('downloads'));
        $this->assertStringContainsString('signature=', (string) $response->json('downloads.0.url'));
        $this->assertSame('report.csv', $response->json('downloads.1.name'));
        $this->assertSame(2, DB::table('mcp_artifacts')->count());
    }

    public function test_advanced_endpoints_are_hidden_while_feature_is_disabled(): void
    {
        app(TenantContext::class)->set('acme');
        $actor = TestUser::query()->create(['name' => 'Marco']);
        $tool = $this->tool();
        McpClient::useTransportResolver(static fn () => new McpAppTransport);
        $outcome = app(McpToolExecutor::class)->invoke($tool->local_name, [], $actor, 'conversation-disabled');
        $appId = (string) data_get($outcome->artifact?->app, 'id');

        $this->actingAs($actor)->putJson('/api/conversations/mcp/apps/'.$appId.'/model-context', [
            'conversation_id' => 'conversation-disabled',
            'content' => [],
        ])->assertNotFound();
    }

    private function tool(): McpConnectionTool
    {
        $server = McpServerDefinition::query()->create([
            'tenant_id' => 'acme',
            'name' => 'Reports MCP',
            'transport' => 'auto',
            'endpoint' => 'https://reports.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Reports',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);

        return McpConnectionTool::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'reports.show',
            'local_name' => 'mcp_reports_show',
            'input_schema_json' => ['type' => 'object'],
            'meta_json' => ['ui' => ['resourceUri' => 'ui://reports/result.html']],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);
    }
}

final class McpAppTransport implements McpTransportContract
{
    public string $resultResourceUri = 'ui://reports/result.html';

    public ?string $lastToolName = null;

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if ($request->method === 'server/discover') {
            return JsonRpcMessage::response($request->id, [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['tools' => [], 'resources' => []],
            ]);
        }
        if ($request->method === 'resources/read') {
            if (($request->params['uri'] ?? null) === 'docs://exports/report.csv') {
                return JsonRpcMessage::response($request->id, ['contents' => [[
                    'uri' => 'docs://exports/report.csv',
                    'mimeType' => 'text/csv',
                    'text' => "id,total\n1,42\n",
                ]]]);
            }

            return JsonRpcMessage::response($request->id, ['contents' => [[
                'uri' => 'ui://reports/result.html',
                'mimeType' => 'text/html;profile=mcp-app',
                'text' => '<!doctype html><html><body><main id="app">Report</main></body></html>',
                '_meta' => [
                    'ui' => [
                        'csp' => [
                            'connectDomains' => [
                                'https://api.widgets.example.test',
                                'https://askmydocs.example.test',
                                'https://mcp-sandbox.example.test',
                            ],
                            'resourceDomains' => ['https://cdn.widgets.example.test'],
                        ],
                        'permissions' => ['camera' => []],
                        'prefersBorder' => true,
                    ],
                    'openai/widgetDescription' => 'Interactive report',
                ],
            ]]]);
        }
        if ($request->method === 'tools/call') {
            $this->lastToolName = is_string($request->params['name'] ?? null) ? $request->params['name'] : null;
            if ($this->lastToolName === 'reports.refresh') {
                return JsonRpcMessage::response($request->id, [
                    'content' => [['type' => 'text', 'text' => 'Page refreshed.']],
                    'structuredContent' => ['page' => $request->params['arguments']['page'] ?? null],
                ]);
            }

            return JsonRpcMessage::response($request->id, [
                'content' => [['type' => 'text', 'text' => 'Fresh report.']],
                'structuredContent' => ['rows' => [['id' => 1]]],
                '_meta' => [
                    'ui' => ['resourceUri' => $this->resultResourceUri],
                    'widgetSecret' => 'widget-only-secret',
                ],
            ]);
        }

        return JsonRpcMessage::errorResponse($request->id, -32601, 'Not scripted.');
    }

    public function notify(JsonRpcMessage $notification): void {}

    public function isHealthy(): bool
    {
        return true;
    }
}
