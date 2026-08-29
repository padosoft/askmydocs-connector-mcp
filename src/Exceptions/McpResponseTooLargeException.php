<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Exceptions;

final class McpResponseTooLargeException extends \RuntimeException
{
    public static function forLimit(int $maxBytes): self
    {
        return new self("MCP HTTP response exceeded the configured {$maxBytes} byte limit.");
    }
}
