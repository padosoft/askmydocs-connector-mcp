<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;

final readonly class McpToolGovernanceService
{
    public function __construct(private TenantContext $tenantContext) {}

    public function setEnabled(McpConnectionTool $tool, bool $enabled): McpConnectionTool
    {
        if ((string) $tool->tenant_id !== $this->tenantContext->current() || $tool->removed_at !== null) {
            throw new AuthorizationException('MCP tool is outside the active catalog.');
        }
        $tool->forceFill([
            'policy' => $enabled ? 'enabled' : 'disabled',
            'enabled' => $enabled,
            'confirmation_required' => $enabled && $tool->risk !== 'read',
        ])->save();

        return $tool->refresh();
    }
}
