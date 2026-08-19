<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Support;

final readonly class McpInvocationOutcome
{
    /**
     * @param  array<string,mixed>|null  $prompt
     * @param  array<string,mixed>|null  $task
     */
    public function __construct(
        public string $status,
        public ?McpArtifactEnvelope $artifact = null,
        public ?string $pendingInteractionId = null,
        public ?array $prompt = null,
        public ?string $taskId = null,
        public ?array $task = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'artifact' => $this->artifact?->toArray(),
            'pending_interaction_id' => $this->pendingInteractionId,
            'prompt' => $this->prompt,
            'task_id' => $this->taskId,
            'task' => $this->task,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function requiresInteraction(): bool
    {
        return in_array($this->status, ['confirmation_required', 'input_required', 'task_accepted'], true);
    }
}
