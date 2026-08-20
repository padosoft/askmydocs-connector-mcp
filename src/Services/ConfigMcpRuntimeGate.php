<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Padosoft\AskMyDocsConnectorMcp\Contracts\McpRuntimeGateContract;

final class ConfigMcpRuntimeGate implements McpRuntimeGateContract
{
    public function active(?string $tenantId = null): bool
    {
        return (bool) config('connector-mcp.enabled', false);
    }
}
