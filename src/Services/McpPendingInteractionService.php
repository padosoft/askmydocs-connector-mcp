<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpPendingInteraction;

final readonly class McpPendingInteractionService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  array<string,mixed>  $continuation
     * @param  array<string,mixed>  $prompt
     */
    public function create(
        McpConnection $connection,
        Model $actor,
        string $conversationId,
        string $kind,
        array $continuation,
        array $prompt,
    ): McpPendingInteraction {
        return McpPendingInteraction::query()->create([
            'tenant_id' => $this->tenantContext->current(),
            'mcp_connector_connection_id' => $connection->getKey(),
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => (string) $actor->getKey(),
            'conversation_id' => $conversationId,
            'kind' => $kind,
            'continuation' => $continuation,
            'prompt_json' => $prompt,
            'expires_at' => now()->addSeconds((int) config('connector-mcp.pending_interaction_ttl_seconds', 900)),
        ]);
    }

    public function consume(string $publicId, Model $actor, string $conversationId): McpPendingInteraction
    {
        return DB::transaction(function () use ($publicId, $actor, $conversationId): McpPendingInteraction {
            $interactionId = DB::table('mcp_connector_pending_interactions')
                ->where('tenant_id', $this->tenantContext->current())
                ->where('public_id', $publicId)
                ->where('actor_type', $actor->getMorphClass())
                ->where('actor_id', (string) $actor->getKey())
                ->where('conversation_id', $conversationId)
                ->lockForUpdate()
                ->value('id');
            if (! is_int($interactionId)) {
                throw new ModelNotFoundException;
            }
            $interaction = McpPendingInteraction::query()->findOrFail($interactionId);
            if ($interaction->consumed_at !== null || $interaction->expires_at->isPast()) {
                throw new \RuntimeException('MCP interaction expired or was already consumed.');
            }
            $interaction->forceFill(['consumed_at' => now()])->save();

            return $interaction;
        }, 3);
    }
}
