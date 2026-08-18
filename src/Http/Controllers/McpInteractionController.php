<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\Concerns\ResolvesActor;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolExecutor;

final class McpInteractionController extends Controller
{
    use ResolvesActor;

    public function __construct(private readonly McpToolExecutor $executor) {}

    public function respond(Request $request, string $interaction): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
            'response' => ['required', 'array'],
        ]);
        $outcome = $this->executor->resume(
            $interaction,
            $data['response'],
            $this->actor($request),
            $data['conversation_id'],
        );

        return response()->json($outcome->toArray());
    }
}
