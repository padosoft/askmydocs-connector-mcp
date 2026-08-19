<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Support;

use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

final class SpyIngestionContract implements ConnectorIngestionContract
{
    /** @var list<array<string,mixed>> */
    public array $dispatches = [];

    /** @var list<array{key:string,value:string}> */
    public array $deletions = [];

    public function dispatchIngestion(string $projectKey, string $relativePath, string $disk, string $title, array $metadata, string $mimeType, string $tenantId): void
    {
        $this->dispatches[] = compact('projectKey', 'relativePath', 'disk', 'title', 'metadata', 'mimeType', 'tenantId');
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        return ['relative' => $relativePath, 'absolute' => $relativePath, 'disk' => 'kb'];
    }

    public function redactContent(string $content): string
    {
        return $content;
    }

    public function emitAudit(string $connectorKey, string $eventType, ?int $installationId = null, ?array $metadata = null): void {}

    public function softDeleteByRemoteId(ConnectorInstallation $installation, string $metadataKey, string $remoteId): bool
    {
        $this->deletions[] = ['key' => $metadataKey, 'value' => $remoteId];

        return true;
    }
}
