<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Services;

final class McpToolPolicy
{
    /**
     * @param  array<string,mixed>  $tool
     * @return array{risk:string,enabled:bool,confirmation_required:bool}
     */
    public function defaults(array $tool): array
    {
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        $readOnly = ($annotations['readOnlyHint'] ?? null) === true;
        $destructive = ($annotations['destructiveHint'] ?? null) === true;
        $risk = $destructive ? 'destructive' : ($readOnly ? 'read' : (($annotations['readOnlyHint'] ?? null) === false ? 'write' : 'unknown'));

        return [
            'risk' => $risk,
            'enabled' => $risk === 'read',
            'confirmation_required' => $risk !== 'read',
        ];
    }

    public function isRiskIncrease(string $oldRisk, string $newRisk): bool
    {
        $rank = ['read' => 0, 'unknown' => 1, 'write' => 2, 'destructive' => 3];

        return ($rank[$newRisk] ?? 3) > ($rank[$oldRisk] ?? 3);
    }
}
