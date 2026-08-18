<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Events;

use Illuminate\Database\Eloquent\Model;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Support\McpInvocationOutcome;

final readonly class McpToolInvocationFinished
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public McpConnectionTool $tool,
        public array $arguments,
        public Model $actor,
        public string $conversationId,
        public ?McpInvocationOutcome $outcome,
        public array $provenance,
        public int $latencyMs,
        public ?\Throwable $exception = null,
    ) {}
}
