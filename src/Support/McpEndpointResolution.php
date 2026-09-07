<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Support;

final readonly class McpEndpointResolution
{
    /** @param list<string> $addresses */
    public function __construct(
        public string $host,
        public int $port,
        public array $addresses,
        public bool $requiresPinning,
    ) {}

    /** @return list<string> */
    public function curlResolveEntries(): array
    {
        if (! $this->requiresPinning) {
            return [];
        }

        return [
            $this->host.':'.$this->port.':'.implode(',', array_map(
                $this->curlAddress(...),
                $this->addresses,
            )),
        ];
    }

    private function curlAddress(string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }
}
