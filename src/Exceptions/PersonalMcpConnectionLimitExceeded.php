<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Exceptions;

final class PersonalMcpConnectionLimitExceeded extends \DomainException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("The personal MCP connection limit of {$limit} has been reached.");
    }
}
