<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorMcp\Contracts\ManagesOwnConnections;
use Padosoft\AskMyDocsConnectorMcp\Contracts\McpRuntimeGateContract;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionManager;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionServerAdapter;
use Padosoft\AskMyDocsConnectorMcp\Services\McpCredentialVault;
use Padosoft\AskMyDocsConnectorMcp\Services\McpEndpointSecurityGuard;
use Padosoft\AskMyDocsConnectorMcp\Services\McpOAuthService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpResourceIngestionService;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final class McpConnector extends BaseConnector implements ManagesOwnConnections
{
    public const KEY = 'mcp';

    public function __construct(
        OAuthCredentialVault $vault,
        TenantContext $tenantContext,
        ConnectorIngestionContract $ingestion,
        private readonly McpResourceIngestionService $resources,
        private readonly McpConnectionManager $connections,
        private readonly McpCredentialVault $mcpVault,
        private readonly McpEndpointSecurityGuard $guard,
        private readonly McpOAuthService $oauth,
        private readonly McpRuntimeGateContract $runtime,
    ) {
        parent::__construct($vault, $tenantContext, $ingestion);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function displayName(): string
    {
        return 'MCP Resources';
    }

    public function initiateOAuth(int $installationId): string
    {
        throw new \LogicException('MCP connections are created through the dedicated MCP connection API.');
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        throw new \LogicException('MCP OAuth callbacks are handled by the dedicated MCP OAuth endpoint.');
    }

    public function syncFull(int $installationId): SyncResult
    {
        if (! $this->runtime->active()) {
            return SyncResult::empty();
        }

        return $this->resources->sync($this->loadInstallation($installationId));
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        return $this->syncFull($installationId);
    }

    public function disconnect(int $installationId): void
    {
        $connection = $this->connectionFor($this->loadInstallation($installationId));
        $this->connections->disconnect($connection);
    }

    public function health(int $installationId): HealthStatus
    {
        if (! $this->runtime->active()) {
            return HealthStatus::degraded('The MCP connector runtime is not active for this tenant.');
        }

        try {
            $connection = $this->connectionFor($this->loadInstallation($installationId));
            if ($connection->server->auth_mode === 'oauth') {
                $this->oauth->refreshIfNeeded($connection);
            }
            $negotiated = McpClient::forServer(
                new McpConnectionServerAdapter($connection, $this->mcpVault, $this->guard),
            )->negotiate();
            if (! array_key_exists('resources', $negotiated->capabilities)) {
                return HealthStatus::degraded('The MCP server does not advertise resources.');
            }

            return HealthStatus::healthy('MCP resources are available.');
        } catch (\Throwable $e) {
            return HealthStatus::errored($e->getMessage());
        }
    }

    private function connectionFor(ConnectorInstallation $installation): McpConnection
    {
        $publicId = data_get($installation->config_json, 'mcp_connection_public_id');
        if (! is_string($publicId) || $publicId === '') {
            throw new \InvalidArgumentException('MCP connector installation has no connection binding.');
        }

        $connection = McpConnection::query()
            ->with('server')
            ->where('tenant_id', $installation->tenant_id)
            ->where('mode', 'shared')
            ->where('public_id', $publicId)
            ->first();
        if ($connection === null) {
            throw new \InvalidArgumentException('The bound shared MCP connection no longer exists.');
        }

        return $connection;
    }
}
