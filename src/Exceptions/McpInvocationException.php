<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Exceptions;

/** Connector-level failure carrying only safe execution telemetry. */
final class McpInvocationException extends \RuntimeException
{
    /** @param array<string,mixed> $provenance */
    public function __construct(
        \Throwable $previous,
        public readonly array $provenance,
        public readonly string $failureCode,
    ) {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
