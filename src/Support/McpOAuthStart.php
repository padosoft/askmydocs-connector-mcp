<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Support;

final readonly class McpOAuthStart
{
    public function __construct(
        public string $authorizationUrl,
        public string $expiresAt,
    ) {}
}
