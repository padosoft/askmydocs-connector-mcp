<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Padosoft\AskMyDocsConnectorMcp\Exceptions\McpResponseTooLargeException;
use Psr\Http\Message\StreamInterface;

/** A writable Guzzle sink that aborts the transfer before the body can grow unbounded. */
final class CappedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $bytesWritten = 0;

    public function __construct(private readonly int $maxBytes)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('The response stream limit must be positive.');
        }

        $resource = fopen('php://temp/maxmemory:'.$maxBytes, 'w+b');
        if ($resource === false) {
            throw new \RuntimeException('Unable to allocate the MCP response stream.');
        }

        $this->stream = Utils::streamFor($resource);
    }

    public function write($string): int
    {
        if ($this->bytesWritten + strlen($string) > $this->maxBytes) {
            throw McpResponseTooLargeException::forLimit($this->maxBytes);
        }

        $written = $this->stream->write($string);
        $this->bytesWritten += $written;

        return $written;
    }
}
