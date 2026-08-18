<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

final class ScriptedTransport implements McpTransportContract
{
    /** @var array<string,JsonRpcMessage|array<string,mixed>> */
    public array $responses = [];

    /** @var list<JsonRpcMessage> */
    public array $requests = [];

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->requests[] = $request;
        $response = $this->responses[(string) $request->method] ?? null;
        if ($response instanceof JsonRpcMessage) {
            return $response;
        }
        if (is_array($response)) {
            return JsonRpcMessage::response($request->id, $response);
        }

        return JsonRpcMessage::errorResponse($request->id, -32601, 'Not scripted');
    }

    public function notify(JsonRpcMessage $notification): void
    {
        $this->requests[] = $notification;
    }

    public function isHealthy(): bool
    {
        return true;
    }
}
