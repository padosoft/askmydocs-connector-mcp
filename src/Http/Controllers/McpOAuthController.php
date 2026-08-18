<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\Concerns\ResolvesActor;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionManager;
use Padosoft\AskMyDocsConnectorMcp\Services\McpDiscoveryService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpOAuthService;

final class McpOAuthController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly McpConnectionManager $connections,
        private readonly McpOAuthService $oauth,
        private readonly McpDiscoveryService $discovery,
    ) {}

    public function begin(Request $request, string $connection): JsonResponse
    {
        $actor = $this->actor($request);
        $model = McpConnection::query()->with('server')
            ->where('tenant_id', $this->tenantContext->current())
            ->where('public_id', $connection)
            ->firstOrFail();
        if ($model->isPersonal()) {
            $this->connections->assertOwner($model, $actor);
        } else {
            $ability = config('connector-mcp.routes.admin_ability');
            if (is_string($ability) && $ability !== '') {
                Gate::authorize($ability);
            }
        }
        $data = $request->validate([
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:191'],
            'ui_destination' => ['nullable', 'string', 'max:2048'],
        ]);
        $scopes = array_values(array_filter($data['scopes'] ?? [], 'is_string'));
        $oauthMetadata = is_array($model->server->oauth_metadata_json) ? $model->server->oauth_metadata_json : [];
        $challenge = is_string($oauthMetadata['www_authenticate'] ?? null) ? $oauthMetadata['www_authenticate'] : null;
        $start = $this->oauth->begin(
            $model,
            $actor,
            $scopes,
            $challenge,
            $data['ui_destination'] ?? null,
        );

        return response()->json(['authorization_url' => $start->authorizationUrl, 'expires_at' => $start->expiresAt]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_string($state) || ! is_string($code)) {
            abort(422, 'OAuth callback is missing state or code.');
        }
        $issuer = $request->query('iss');
        $result = $this->oauth->callback($state, $code, $this->actor($request), is_string($issuer) ? $issuer : null);
        try {
            $this->discovery->discover($result['connection']);
            $status = 'connected';
        } catch (\Throwable) {
            $status = 'discovery_failed';
        }
        $separator = str_contains($result['destination'], '?') ? '&' : '?';

        return redirect()->to($result['destination'].$separator.'mcp='.$status);
    }

    public function clientMetadata(): JsonResponse
    {
        $callback = url((string) config('connector-mcp.oauth.callback_path'));
        $clientId = $this->oauth->clientMetadataUrl()
            ?? url((string) config('connector-mcp.oauth.client_metadata_path'));

        return response()->json([
            'client_id' => $clientId,
            'client_name' => (string) config('connector-mcp.oauth.client_name', 'AskMyDocs'),
            'client_uri' => config('connector-mcp.oauth.client_uri'),
            'redirect_uris' => [$callback],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }
}
