<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Contracts\McpRuntimeGateContract;
use Padosoft\AskMyDocsConnectorMcp\Events\McpToolInvocationFinished;
use Padosoft\AskMyDocsConnectorMcp\Exceptions\McpInvocationException;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Support\McpInvocationOutcome;
use Padosoft\AskMyDocsMcpPack\Contracts\McpProtocolAwareTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpAuthorizationException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpRemoteErrorException;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\McpNegotiationResult;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;

final readonly class McpToolExecutor
{
    public function __construct(
        private TenantContext $tenantContext,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
        private McpOAuthService $oauth,
        private McpPendingInteractionService $pending,
        private McpRemoteTaskService $tasks,
        private McpArtifactEnvelopeFactory $artifacts,
        private McpAppInstanceService $apps,
        private McpRuntimeGateContract $runtime,
        private McpToolCatalogFingerprint $fingerprint,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $continuation
     */
    public function invoke(
        string $localName,
        array $arguments,
        Model $actor,
        string $conversationId,
        ?string $projectKey = null,
        bool $confirmed = false,
        array $continuation = [],
    ): McpInvocationOutcome {
        $this->assertRuntimeActive();
        $tool = $this->authorizedTool($localName, $actor, $projectKey);
        $connection = $tool->connection;
        if ($tool->confirmation_required && ! $confirmed) {
            $interaction = $this->pending->create(
                $connection,
                $actor,
                $conversationId,
                'confirmation',
                compact('localName', 'arguments', 'projectKey'),
                ['tool' => $localName, 'risk' => $tool->risk, 'message' => 'Confirm this MCP tool call.'],
            );

            return new McpInvocationOutcome('confirmation_required', pendingInteractionId: $interaction->public_id, prompt: $interaction->prompt_json);
        }

        $invocationId = (string) Str::uuid();
        $startedAt = microtime(true);
        $baseProvenance = [
            'server_id' => $connection->server->getKey(),
            'server_name' => $connection->server->name,
            'connection_id' => $connection->public_id,
            'tool_remote_name' => $tool->remote_name,
            'tool_local_name' => $tool->local_name,
            'invocation_id' => $invocationId,
            'timestamp' => now()->toIso8601String(),
        ];
        $client = null;
        $clients = [];
        $cacheHits = [];
        $runtimeProvenance = [];
        try {
            $oauthStartedAt = microtime(true);
            if ($connection->server->auth_mode === 'oauth') {
                $this->oauth->refreshIfNeeded($connection);
            }
            $runtimeProvenance['oauth_refresh_ms'] = $this->elapsedMs($oauthStartedAt);
            $clientStartedAt = microtime(true);
            $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
            $cacheHits[] = $this->reuseRecentModernNegotiation($client, $connection);
            $clients[] = $client;
            $runtimeProvenance['client_prepare_ms'] = $this->elapsedMs($clientStartedAt);
            $toolStartedAt = microtime(true);
            try {
                $result = $client->callToolResult((string) $tool->remote_name, $arguments, $continuation);
            } catch (McpAuthorizationException $exception) {
                if (! $tool->read_only
                    || $connection->server->auth_mode !== 'oauth'
                    || $exception->httpStatus !== 401
                    || $exception->oauthError === 'insufficient_scope') {
                    throw $exception;
                }

                $refreshStartedAt = microtime(true);
                $this->oauth->refreshAfterUnauthorized($connection);
                $runtimeProvenance['oauth_refresh_ms'] += $this->elapsedMs($refreshStartedAt);
                $runtimeProvenance['recovery'] = 'oauth_refresh';
                $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
                $cacheHits[] = $this->reuseRecentModernNegotiation($client, $connection);
                $clients[] = $client;
                $result = $client->callToolResult((string) $tool->remote_name, $arguments, $continuation);
            } catch (McpRemoteErrorException $exception) {
                if (! $tool->read_only || ! $this->requiresRenegotiation($exception)) {
                    throw $exception;
                }

                $runtimeProvenance['recovery'] = 'renegotiated';
                $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
                $cacheHits[] = false;
                $clients[] = $client;
                $result = $client->callToolResult((string) $tool->remote_name, $arguments, $continuation);
            }
            if ($cacheHits[0] === false) {
                $this->persistNegotiation($connection, $client);
            }
            $runtimeProvenance = array_replace(
                $runtimeProvenance,
                $this->clientProvenance($clients, $cacheHits, $this->elapsedMs($toolStartedAt)),
            );
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $provenance = $baseProvenance + $runtimeProvenance + ['latency_ms' => $latencyMs];

            if ($result->isTask()) {
                $task = $this->tasks->capture(
                    $tool,
                    $actor,
                    $conversationId,
                    $invocationId,
                    $result,
                    $provenance,
                );
                $taskPayload = $task->toPublicArray();
                $outcome = new McpInvocationOutcome(
                    status: 'task_accepted',
                    taskId: $task->public_id,
                    task: $taskPayload,
                    prompt: [
                        'message' => $task->status === 'input_required'
                            ? 'The MCP task requires additional input.'
                            : 'The MCP task is running.',
                        'inputRequests' => $task->input_requests,
                    ],
                );
                $this->emit(new McpToolInvocationFinished($tool, $arguments, $actor, $conversationId, $outcome, $provenance, $latencyMs));

                return $outcome;
            }

            $artifact = $this->artifacts->make($result, $provenance, (string) $actor->getKey());
            $app = $this->apps->capture($tool, $actor, $conversationId, $arguments, $result, $artifact);
            if ($app !== null) {
                $artifact = $artifact->withApp($app);
            }

            if ($result->isInputRequired()) {
                $interaction = $this->pending->create(
                    $connection,
                    $actor,
                    $conversationId,
                    'mrtr',
                    [
                        'localName' => $localName,
                        'arguments' => $arguments,
                        'projectKey' => $projectKey,
                        'requestState' => $result->requestState,
                    ],
                    ['inputRequests' => $result->inputRequests, 'message' => 'The MCP server requires additional input.'],
                );
                $outcome = new McpInvocationOutcome('input_required', $artifact, $interaction->public_id, $interaction->prompt_json);
                $this->emit(new McpToolInvocationFinished($tool, $arguments, $actor, $conversationId, $outcome, $provenance, $latencyMs));

                return $outcome;
            }

            $outcome = new McpInvocationOutcome($result->isError ? 'error' : 'completed', $artifact);
            $this->emit(new McpToolInvocationFinished($tool, $arguments, $actor, $conversationId, $outcome, $provenance, $latencyMs));

            return $outcome;
        } catch (\Throwable $exception) {
            $this->captureOAuthChallenge($connection, $client);
            if ($clients !== []) {
                $runtimeProvenance = array_replace(
                    $runtimeProvenance,
                    $this->clientProvenance($clients, $cacheHits, null),
                );
            }
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $failureProvenance = $baseProvenance + $runtimeProvenance + ['latency_ms' => $latencyMs];
            $this->emit(new McpToolInvocationFinished(
                $tool,
                $arguments,
                $actor,
                $conversationId,
                null,
                $failureProvenance,
                $latencyMs,
                $exception,
            ));

            throw new McpInvocationException(
                $exception,
                $failureProvenance,
                $this->failureCode($exception),
            );
        }
    }

    /** @param array<string,mixed> $response */
    public function resume(string $pendingId, array $response, Model $actor, string $conversationId): McpInvocationOutcome
    {
        $this->assertRuntimeActive();
        $interaction = $this->pending->consume($pendingId, $actor, $conversationId);
        $continuation = $interaction->continuation;
        $localName = (string) ($continuation['localName'] ?? '');
        $arguments = is_array($continuation['arguments'] ?? null) ? $continuation['arguments'] : [];
        $projectKey = is_string($continuation['projectKey'] ?? null) ? $continuation['projectKey'] : null;
        if ($interaction->kind === 'confirmation') {
            if (($response['confirmed'] ?? false) !== true) {
                return new McpInvocationOutcome('declined');
            }

            return $this->invoke($localName, $arguments, $actor, $conversationId, $projectKey, true);
        }

        return $this->invoke(
            $localName,
            $arguments,
            $actor,
            $conversationId,
            $projectKey,
            true,
            ['requestState' => $continuation['requestState'] ?? null, 'inputResponses' => $response],
        );
    }

    private function authorizedTool(string $localName, Model $actor, ?string $projectKey): McpConnectionTool
    {
        $query = McpConnectionTool::query();
        $query->whereHas('connection', function ($query) use ($actor, $projectKey): void {
            $query->where('status', 'active')
                ->where(function ($scope) use ($actor): void {
                    $scope->where('mode', 'shared')
                        ->orWhere(function ($personal) use ($actor): void {
                            $personal->where('mode', 'personal')
                                ->where('owner_type', $actor->getMorphClass())
                                ->where('owner_id', (string) $actor->getKey());
                        });
                })
                ->where(function ($projects) use ($projectKey): void {
                    $projects->whereNull('project_key');
                    if ($projectKey !== null) {
                        $projects->orWhere('project_key', $projectKey);
                    }
                });
        });
        $toolId = $query
            ->where('tenant_id', $this->tenantContext->current())
            ->where('local_name', $localName)
            ->where('enabled', true)
            ->whereNull('removed_at')
            ->value('id');
        if (! is_int($toolId)) {
            throw new ModelNotFoundException;
        }

        return McpConnectionTool::query()->with(['connection.server'])->findOrFail($toolId);
    }

    private function emit(McpToolInvocationFinished $event): void
    {
        try {
            event($event);
        } catch (\Throwable $exception) {
            try {
                report($exception);
            } catch (\Throwable) {
                // Observability must never change the tool invocation outcome.
            }
        }
    }

    private function captureOAuthChallenge(McpConnection $connection, ?McpClient $client): void
    {
        if ($connection->server->auth_mode !== 'oauth') {
            return;
        }
        $transport = $client?->transport();
        if (! $transport instanceof McpProtocolAwareTransportContract) {
            return;
        }
        $status = $transport->lastStatusCode();
        if (! in_array($status, [401, 403], true)) {
            return;
        }
        $challenge = null;
        foreach ($transport->lastResponseHeaders() as $header => $values) {
            if (strcasecmp($header, 'www-authenticate') === 0 && is_string($values[0] ?? null)) {
                $challenge = $values[0];
                break;
            }
        }
        if ($challenge === null) {
            return;
        }
        try {
            $this->oauth->requireReauthorization($connection, $challenge, $status);
        } catch (\Throwable) {
            // Authorization state tracking must never replace the tool error.
        }
    }

    private function reuseRecentModernNegotiation(McpClient $client, McpConnection $connection): bool
    {
        $server = $connection->server;
        $ttl = max(0, (int) config('connector-mcp.http.runtime_negotiation_ttl_seconds', 900));
        $discoveredAt = $connection->last_discovered_at;
        if ($ttl === 0
            || $server->negotiated_era !== McpProtocolEra::Modern->value
            || $server->negotiated_version !== McpClient::MODERN_PROTOCOL_VERSION
            || $discoveredAt === null
            || $discoveredAt->lt(now()->subSeconds($ttl))
            || ! is_string($connection->catalog_hash)
            || ! hash_equals($connection->catalog_hash, $this->fingerprint->forConnection($connection))) {
            return false;
        }

        $client->useNegotiation(new McpNegotiationResult(
            era: McpProtocolEra::Modern,
            protocolVersion: McpClient::MODERN_PROTOCOL_VERSION,
            capabilities: is_array($server->capabilities_json) ? $server->capabilities_json : [],
            serverInfo: is_array($server->server_info_json) ? $server->server_info_json : [],
        ));

        return true;
    }

    /**
     * @param  list<McpClient>  $clients
     * @param  list<bool>  $cacheHits
     * @return array<string,mixed>
     */
    private function clientProvenance(array $clients, array $cacheHits, ?int $toolCallMs): array
    {
        if ($clients === []) {
            throw new \LogicException('At least one MCP client is required to build invocation provenance.');
        }
        $client = $clients[count($clients) - 1];
        $negotiated = $client->negotiatedProtocol();
        $provenance = [
            'negotiation_cache_hit' => ($cacheHits[0] ?? false) === true,
            'physical_request_count' => array_sum(array_map(
                static fn (McpClient $attempt): int => $attempt->physicalRequestCount(),
                $clients,
            )),
            'protocol_era' => $negotiated?->era->value,
            'protocol_version' => $negotiated?->protocolVersion,
        ];
        if ($toolCallMs !== null) {
            $provenance['tool_pipeline_ms'] = $toolCallMs;
            $provenance['tool_call_ms'] = $toolCallMs;
        }

        $metrics = [];
        foreach ($clients as $attempt) {
            $transport = $attempt->transport();
            if (! method_exists($transport, 'requestMetrics')) {
                continue;
            }
            $attemptMetrics = $transport->requestMetrics();
            if (is_array($attemptMetrics)) {
                array_push($metrics, ...$attemptMetrics);
            }
        }
        if ($metrics === []) {
            return $provenance;
        }
        $sum = static fn (string $key, ?callable $filter = null): int => array_sum(array_map(
            static fn (mixed $metric): int => is_array($metric) && ($filter === null || $filter($metric))
                ? (int) ($metric[$key] ?? 0)
                : 0,
            $metrics,
        ));
        $duration = static fn (array $metric): int => (int) ($metric['endpoint_guard_ms'] ?? 0)
            + (int) ($metric['http_ms'] ?? 0)
            + (int) ($metric['decode_ms'] ?? 0);
        $isDiscovery = static fn (array $metric): bool => in_array(
            $metric['method'] ?? null,
            ['server/discover', 'initialize'],
            true,
        );
        $isToolCall = static fn (array $metric): bool => ($metric['method'] ?? null) === 'tools/call';
        $provenance['endpoint_guard_dns_ms'] = $sum('endpoint_guard_ms');
        $provenance['http_ms'] = $sum('http_ms');
        $provenance['decode_ms'] = $sum('decode_ms');
        $provenance['discovery_ms'] = array_sum(array_map(
            static fn (mixed $metric): int => is_array($metric) && $isDiscovery($metric) ? $duration($metric) : 0,
            $metrics,
        ));
        $provenance['tool_call_ms'] = array_sum(array_map(
            static fn (mixed $metric): int => is_array($metric) && $isToolCall($metric) ? $duration($metric) : 0,
            $metrics,
        ));

        return $provenance;
    }

    private function requiresRenegotiation(McpRemoteErrorException $exception): bool
    {
        if ($exception->rpcCode === -32601) {
            return true;
        }

        return $exception->rpcCode === -32602
            && preg_match('/\b(protocol|version|method)\b/i', $exception->getMessage()) === 1;
    }

    private function persistNegotiation(McpConnection $connection, McpClient $client): void
    {
        $negotiated = $client->negotiatedProtocol();
        if ($negotiated === null) {
            return;
        }
        $connection->server->forceFill([
            'negotiated_era' => $negotiated->era->value,
            'negotiated_version' => $negotiated->protocolVersion,
            'capabilities_json' => $negotiated->capabilities,
            'server_info_json' => $negotiated->serverInfo,
            'last_discovered_at' => now(),
        ])->save();
        $connection->forceFill([
            'last_discovered_at' => now(),
            'catalog_hash' => $this->fingerprint->forConnection($connection),
        ])->save();
    }

    private function failureCode(\Throwable $exception): string
    {
        if ($exception instanceof McpAuthorizationException) {
            return $exception->oauthError === 'insufficient_scope'
                ? 'oauth_insufficient_scope'
                : 'oauth_authorization_required';
        }
        if ($exception instanceof McpRemoteErrorException) {
            return 'mcp_remote_error';
        }

        return 'mcp_transport_error';
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function assertRuntimeActive(): void
    {
        if (! $this->runtime->active($this->tenantContext->current())) {
            throw new \RuntimeException('The MCP connector runtime is not active for this tenant.');
        }
    }
}
