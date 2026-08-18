<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Support;

final readonly class McpInvocationOutcome
{
    /** @param array<string,mixed>|null $prompt */
    public function __construct(
        public string $status,
        public ?McpArtifactEnvelope $artifact = null,
        public ?string $pendingInteractionId = null,
        public ?array $prompt = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'artifact' => $this->artifact?->toArray(),
            'pending_interaction_id' => $this->pendingInteractionId,
            'prompt' => $this->prompt,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
