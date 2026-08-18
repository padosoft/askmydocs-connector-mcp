<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Support\McpInvocationOutcome;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final readonly class McpToolExecutor
{
    public function __construct(
        private TenantContext $tenantContext,
        private McpCredentialVault $vault,
        private McpEndpointSecurityGuard $guard,
        private McpOAuthService $oauth,
        private McpPendingInteractionService $pending,
        private McpArtifactEnvelopeFactory $artifacts,
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
        if ($connection->server->auth_mode === 'oauth') {
            $this->oauth->refreshIfNeeded($connection);
        }
        $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $this->vault, $this->guard));
        $result = $client->callToolResult((string) $tool->remote_name, $arguments, $continuation);
        $provenance = [
            'server_id' => $connection->server->getKey(),
            'connection_id' => $connection->public_id,
            'tool_remote_name' => $tool->remote_name,
            'tool_local_name' => $tool->local_name,
            'invocation_id' => $invocationId,
            'timestamp' => now()->toIso8601String(),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
        $artifact = $this->artifacts->make($result, $provenance, (string) $actor->getKey());

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

            return new McpInvocationOutcome('input_required', $artifact, $interaction->public_id, $interaction->prompt_json);
        }

        return new McpInvocationOutcome($result->isError ? 'error' : ($result->isTask() ? 'task_accepted' : 'completed'), $artifact);
    }

    /** @param array<string,mixed> $response */
    public function resume(string $pendingId, array $response, Model $actor, string $conversationId): McpInvocationOutcome
    {
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
}
