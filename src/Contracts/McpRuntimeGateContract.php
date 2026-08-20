<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Contracts;

interface McpRuntimeGateContract
{
    public function active(?string $tenantId = null): bool;
}
