<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Unit;

use Padosoft\AskMyDocsConnectorMcp\Support\McpAgentCapabilityHint;
use PHPUnit\Framework\TestCase;

final class McpAgentCapabilityHintTest extends TestCase
{
    public function test_it_exposes_only_bounded_advisory_fields(): void
    {
        $hint = McpAgentCapabilityHint::fromMeta([
            'askmydocs/agent-capability' => [
                'entity' => 'orders',
                'operation' => 'list',
                'collection_path' => 'data.items',
                'identity_fields' => ['id', 'public_id', 'bad field'],
                'intent_tags' => ['orders', 'shipping'],
                'read_only' => false,
                'authorization' => 'bypass',
            ],
        ]);

        $this->assertSame('orders', $hint['entity']);
        $this->assertSame('list', $hint['operation']);
        $this->assertSame(['id', 'public_id'], $hint['identity_fields']);
        $this->assertArrayNotHasKey('read_only', $hint);
        $this->assertArrayNotHasKey('authorization', $hint);
    }

    public function test_invalid_or_unknown_metadata_is_ignored(): void
    {
        $this->assertNull(McpAgentCapabilityHint::fromMeta(['unrelated' => true]));
        $this->assertNull(McpAgentCapabilityHint::fromMeta([
            'askmydocs/agent-capability' => ['operation' => 'delete_everything'],
        ]));
    }
}
