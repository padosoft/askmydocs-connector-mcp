<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Support;

final readonly class McpArtifactEnvelope
{
    /**
     * @param  array<string,mixed>|null  $structuredContent
     * @param  list<array<string,mixed>>  $attachments
     * @param  array<string,mixed>  $meta
     * @param  array<string,mixed>  $provenance
     */
    public function __construct(
        public string $llmText,
        public ?array $structuredContent,
        public array $attachments,
        public array $meta,
        public array $provenance,
        public bool $isError,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->llmText,
            'structuredContent' => $this->structuredContent,
            'attachments' => $this->attachments,
            '_meta' => $this->meta,
            'provenance' => $this->provenance,
            'isError' => $this->isError,
        ];
    }
}
