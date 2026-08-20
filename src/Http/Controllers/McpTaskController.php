<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorMcp\Contracts\McpRuntimeGateContract;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\Concerns\ResolvesActor;
use Padosoft\AskMyDocsConnectorMcp\Services\McpRemoteTaskService;

final class McpTaskController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly McpRemoteTaskService $tasks,
        private readonly McpRuntimeGateContract $runtime,
    ) {}

    public function show(Request $request, string $task): JsonResponse
    {
        abort_unless($this->runtime->active(), 404);
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
        ]);
        $model = $this->tasks->status(
            $task,
            $this->actor($request),
            $data['conversation_id'],
        );

        return response()->json($model->toPublicArray());
    }

    public function input(Request $request, string $task): JsonResponse
    {
        abort_unless($this->runtime->active(), 404);
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
            'input_responses' => ['required', 'array'],
            'request_state' => ['nullable', 'string', 'max:16384'],
        ]);
        $model = $this->tasks->submitInput(
            $task,
            $data['input_responses'],
            $this->actor($request),
            $data['conversation_id'],
            $data['request_state'] ?? null,
        );

        return response()->json($model->toPublicArray());
    }

    public function cancel(Request $request, string $task): JsonResponse
    {
        abort_unless($this->runtime->active(), 404);
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
        ]);
        $model = $this->tasks->cancel(
            $task,
            $this->actor($request),
            $data['conversation_id'],
        );

        return response()->json($model->toPublicArray(), 202);
    }
}
