<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Padosoft\AskMyDocsConnectorMcp\Contracts\McpRuntimeGateContract;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\Concerns\ResolvesActor;
use Padosoft\AskMyDocsConnectorMcp\Services\McpAppInstanceService;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolExecutor;
use Padosoft\AskMyDocsConnectorMcp\Support\McpArtifactEnvelope;
use Padosoft\AskMyDocsConnectorMcp\Support\McpInvocationOutcome;

final class McpAppController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly McpAppInstanceService $apps,
        private readonly McpToolExecutor $executor,
        private readonly McpRuntimeGateContract $runtime,
    ) {}

    public function show(Request $request, string $app): JsonResponse
    {
        abort_unless($this->runtime->active(), 404);
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
        ]);

        return response()->json($this->apps->resolve(
            $app,
            $this->actor($request),
            $data['conversation_id'],
        ));
    }

    public function callTool(Request $request, string $app): JsonResponse
    {
        abort_unless($this->runtime->active(), 404);
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:255'],
            'arguments' => ['sometimes', 'array'],
        ]);
        $actor = $this->actor($request);
        $instance = $this->apps->authorized($app, $actor, $data['conversation_id']);
        $tool = $this->apps->callableTool($instance, $data['name']);
        $outcome = $this->executor->invoke(
            (string) $tool->local_name,
            is_array($data['arguments'] ?? null) ? $data['arguments'] : [],
            $actor,
            $data['conversation_id'],
            $instance->connection->project_key,
        );

        $payload = $outcome->toArray();
        $payload['result'] = $this->bridgeResult($outcome);

        return response()->json(
            $payload,
            $outcome->requiresInteraction() ? 202 : 200,
        );
    }

    public function updateModelContext(Request $request, string $app): JsonResponse
    {
        $this->authorizeAdvanced();
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
            'content' => ['sometimes', 'array', 'max:32'],
            'structuredContent' => ['sometimes', 'array'],
        ]);
        $instance = $this->apps->authorized($app, $this->actor($request), $data['conversation_id']);
        try {
            $this->apps->replaceModelContext($instance, $data);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['context' => $e->getMessage()]);
        }

        return response()->json((object) []);
    }

    public function download(Request $request, string $app): JsonResponse
    {
        $this->authorizeAdvanced();
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:191'],
            'contents' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('connector-mcp.apps.max_download_items', 5))],
            'contents.*' => ['required', 'array'],
        ]);
        $actor = $this->actor($request);
        $instance = $this->apps->authorized($app, $actor, $data['conversation_id']);
        try {
            $downloads = $this->apps->prepareDownloads($instance, $actor, $data['contents']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['contents' => $e->getMessage()]);
        }

        return response()->json(['downloads' => $downloads]);
    }

    private function authorizeAdvanced(): void
    {
        abort_unless($this->runtime->active() && $this->apps->advancedEnabled(), 404);
    }

    /** @return array<string,mixed> */
    private function bridgeResult(McpInvocationOutcome $outcome): array
    {
        $artifact = $outcome->artifact;
        if (! $artifact instanceof McpArtifactEnvelope) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => match ($outcome->status) {
                        'confirmation_required' => 'This tool call requires user confirmation.',
                        'input_required' => 'This tool call requires additional user input.',
                        'task_accepted' => 'The MCP server accepted this call as a remote task.',
                        default => 'The MCP tool returned no content.',
                    },
                ]],
                'isError' => $outcome->status === 'error',
            ];
        }

        $meta = $artifact->meta;
        if ($artifact->attachments !== []) {
            $meta['askmydocs/attachments'] = $artifact->attachments;
        }

        return array_filter([
            'content' => $artifact->llmText !== ''
                ? [['type' => 'text', 'text' => $artifact->llmText]]
                : [],
            'structuredContent' => $artifact->structuredContent,
            'isError' => $artifact->isError,
            '_meta' => $meta,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
