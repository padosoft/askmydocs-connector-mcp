<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;

final readonly class McpConnectionManager
{
    public function __construct(
        private TenantContext $tenantContext,
        private McpEndpointCanonicalizer $canonicalizer,
        private McpEndpointSecurityGuard $securityGuard,
        private McpCredentialVault $vault,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function createShared(array $attributes, string $createdBy): McpConnection
    {
        return $this->create($attributes, 'shared', null, $createdBy);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function createPersonal(array $attributes, Model $owner): McpConnection
    {
        if (isset($attributes['server_id'])) {
            return $this->createPersonalForApprovedServer((int) $attributes['server_id'], $attributes, $owner);
        }

        return $this->create($attributes, 'personal', $owner, (string) $owner->getKey());
    }

    /** @param array<string,mixed> $attributes */
    private function createPersonalForApprovedServer(int $serverId, array $attributes, Model $owner): McpConnection
    {
        $tenant = $this->tenantContext->current();
        $serverRow = DB::table('mcp_connector_servers')
            ->where('tenant_id', $tenant)
            ->where('catalog_scope', 'tenant')
            ->whereIn('auth_mode', ['none', 'oauth'])
            ->whereNull('legacy_headers_encrypted')
            ->where('id', $serverId)
            ->first();
        if ($serverRow === null) {
            throw new ModelNotFoundException;
        }
        $server = (new McpServerDefinition)->newFromBuilder((array) $serverRow);
        $this->securityGuard->assertAllowed((string) $server->endpoint, true);

        return McpConnection::query()->create([
            'tenant_id' => $tenant,
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'personal',
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'label' => (string) ($attributes['label'] ?? $server->name),
            'project_key' => $attributes['project_key'] ?? null,
            'status' => McpConnection::STATUS_PENDING,
        ])->load('server');
    }

    /** @param array<string,mixed> $attributes */
    private function create(array $attributes, string $mode, ?Model $owner, string $createdBy): McpConnection
    {
        $endpoint = $this->canonicalizer->canonicalize((string) ($attributes['endpoint'] ?? ''));
        $this->securityGuard->assertAllowed($endpoint, $mode === 'personal');
        $transport = (string) ($attributes['transport'] ?? 'auto');
        if (! in_array($transport, (array) config('connector-mcp.allowed_transports', []), true)) {
            throw new \InvalidArgumentException("Unsupported MCP transport [{$transport}].");
        }
        if ($mode === 'personal' && $transport === 'stdio_imported') {
            throw new \InvalidArgumentException('Personal MCP connections cannot use stdio.');
        }
        $authMode = isset($attributes['bearer']) && trim((string) $attributes['bearer']) !== '' ? 'bearer' : 'none';
        $tenant = $this->tenantContext->current();

        return DB::transaction(function () use ($attributes, $mode, $owner, $createdBy, $endpoint, $transport, $authMode, $tenant): McpConnection {
            $server = McpServerDefinition::query()->create([
                'tenant_id' => $tenant,
                'name' => (string) ($attributes['name'] ?? 'MCP Server'),
                'catalog_scope' => $mode === 'personal' ? 'personal' : 'tenant',
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getKey(),
                'transport' => $transport,
                'auth_mode' => $authMode,
                'endpoint' => $endpoint,
                'endpoint_hash' => hash('sha256', $endpoint),
                'status' => McpServerDefinition::STATUS_PENDING,
                'created_by' => $createdBy,
            ]);
            $connection = McpConnection::query()->create([
                'tenant_id' => $tenant,
                'mcp_connector_server_id' => $server->getKey(),
                'mode' => $mode,
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getKey(),
                'label' => (string) ($attributes['label'] ?? $server->name),
                'project_key' => $attributes['project_key'] ?? null,
                'status' => McpConnection::STATUS_PENDING,
            ]);
            if ($authMode === 'bearer') {
                $this->vault->putBearer($connection, (string) $attributes['bearer']);
            }

            return $connection->load('server');
        });
    }

    public function disconnect(McpConnection $connection): void
    {
        $this->assertTenant($connection);
        $this->vault->clear($connection);
        $connection->forceFill(['status' => McpConnection::STATUS_DISABLED])->save();
    }

    /** @param array<string,mixed> $attributes */
    public function update(McpConnection $connection, array $attributes): McpConnection
    {
        $this->assertTenant($connection);
        $connection->loadMissing('server');
        $serverChanges = [];
        $requiresDiscovery = false;
        if (array_key_exists('name', $attributes)) {
            $serverChanges['name'] = (string) $attributes['name'];
        }
        if (array_key_exists('endpoint', $attributes)) {
            $endpoint = $this->canonicalizer->canonicalize((string) $attributes['endpoint']);
            $this->securityGuard->assertAllowed($endpoint, $connection->isPersonal());
            $serverChanges['endpoint'] = $endpoint;
            $serverChanges['endpoint_hash'] = hash('sha256', $endpoint);
            $requiresDiscovery = $endpoint !== $connection->server->endpoint;
        }
        if (array_key_exists('transport', $attributes)) {
            $transport = (string) $attributes['transport'];
            if (! in_array($transport, (array) config('connector-mcp.allowed_transports', []), true)
                || ($connection->isPersonal() && $transport === 'stdio_imported')) {
                throw new \InvalidArgumentException("Unsupported MCP transport [{$transport}].");
            }
            $serverChanges['transport'] = $transport;
            $requiresDiscovery = $requiresDiscovery || $transport !== $connection->server->transport;
        }
        if ($serverChanges !== [] && $connection->isPersonal() && $connection->server->catalog_scope === 'tenant') {
            throw new AuthorizationException('A personal connection cannot modify a tenant-approved MCP server.');
        }
        if (array_key_exists('bearer', $attributes)) {
            $requiresDiscovery = true;
        }

        return DB::transaction(function () use ($connection, $attributes, $serverChanges, $requiresDiscovery): McpConnection {
            if ($serverChanges !== []) {
                if ($requiresDiscovery) {
                    $serverChanges += [
                        'status' => McpServerDefinition::STATUS_PENDING,
                        'negotiated_era' => null,
                        'negotiated_version' => null,
                        'capabilities_json' => null,
                        'server_info_json' => null,
                        'error_json' => null,
                    ];
                }
                $connection->server->forceFill($serverChanges)->save();
            }
            $connectionChanges = [];
            if (array_key_exists('label', $attributes)) {
                $connectionChanges['label'] = (string) $attributes['label'];
            }
            if (array_key_exists('project_key', $attributes)) {
                $connectionChanges['project_key'] = $attributes['project_key'];
            }
            if ($requiresDiscovery) {
                $connectionChanges['status'] = McpConnection::STATUS_PENDING;
                $connectionChanges['error_json'] = null;
            }
            if ($connectionChanges !== []) {
                $connection->forceFill($connectionChanges)->save();
            }

            if (array_key_exists('bearer', $attributes)) {
                $bearer = is_string($attributes['bearer']) ? trim($attributes['bearer']) : '';
                if ($bearer === '') {
                    $this->vault->clear($connection);
                    $connection->server->forceFill(['auth_mode' => 'none'])->save();
                } else {
                    $this->vault->putBearer($connection, $bearer);
                    $connection->server->forceFill(['auth_mode' => 'bearer'])->save();
                }
            }

            return $connection->refresh()->load('server');
        });
    }

    public function delete(McpConnection $connection): void
    {
        $this->assertTenant($connection);
        DB::transaction(function () use ($connection): void {
            $server = $connection->server()->first();
            $connection->delete();
            if ($server !== null && ! $server->connections()->exists()) {
                $server->delete();
            }
        });
    }

    public function assertOwner(McpConnection $connection, Model $owner): void
    {
        $this->assertTenant($connection);
        if (! $connection->isPersonal()
            || $connection->owner_type !== $owner->getMorphClass()
            || (string) $connection->owner_id !== (string) $owner->getKey()) {
            throw new AuthorizationException('MCP connection is not owned by the authenticated user.');
        }
    }

    private function assertTenant(McpConnection $connection): void
    {
        if ((string) $connection->tenant_id !== $this->tenantContext->current()) {
            throw new AuthorizationException('MCP connection is outside the active tenant.');
        }
    }
}
