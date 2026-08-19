<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\AdminMcpConnectionsController;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\McpInteractionController;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\McpOAuthController;
use Padosoft\AskMyDocsConnectorMcp\Http\Controllers\PersonalMcpConnectionsController;
use Padosoft\AskMyDocsConnectorMcp\Http\Middleware\EnsureMcpConnectorEnabled;

Route::get((string) config('connector-mcp.oauth.client_metadata_path', '/.well-known/mcp-client.json'), [McpOAuthController::class, 'clientMetadata'])
    ->middleware(EnsureMcpConnectorEnabled::class)
    ->name('mcp-connector.client-metadata');

Route::middleware(array_merge([EnsureMcpConnectorEnabled::class], (array) config('connector-mcp.routes.middleware', ['api', 'auth'])))
    ->group(function (): void {
        Route::get((string) config('connector-mcp.oauth.callback_path'), [McpOAuthController::class, 'callback'])
            ->name('mcp-connector.oauth.callback');

        Route::prefix('api/admin/connectors/mcp')->group(function (): void {
            Route::get('/', [AdminMcpConnectionsController::class, 'index']);
            Route::post('/', [AdminMcpConnectionsController::class, 'store']);
            Route::put('/{connection}', [AdminMcpConnectionsController::class, 'update']);
            Route::post('/{connection}/discover', [AdminMcpConnectionsController::class, 'discover']);
            Route::post('/{connection}/test', [AdminMcpConnectionsController::class, 'discover']);
            Route::post('/{connection}/disconnect', [AdminMcpConnectionsController::class, 'disconnect']);
            Route::delete('/{connection}', [AdminMcpConnectionsController::class, 'destroy']);
            Route::put('/{connection}/tools/{tool}', [AdminMcpConnectionsController::class, 'setTool']);
            Route::put('/{connection}/resources/{resource}', [AdminMcpConnectionsController::class, 'setResource']);
            Route::post('/{connection}/oauth', [McpOAuthController::class, 'begin']);
        });

        Route::prefix('api/me/connected-apps/mcp')->group(function (): void {
            Route::get('/', [PersonalMcpConnectionsController::class, 'index']);
            Route::get('/catalog', [PersonalMcpConnectionsController::class, 'catalog']);
            Route::post('/', [PersonalMcpConnectionsController::class, 'store']);
            Route::put('/{connection}', [PersonalMcpConnectionsController::class, 'update']);
            Route::post('/{connection}/discover', [PersonalMcpConnectionsController::class, 'discover']);
            Route::post('/{connection}/test', [PersonalMcpConnectionsController::class, 'discover']);
            Route::post('/{connection}/disconnect', [PersonalMcpConnectionsController::class, 'disconnect']);
            Route::delete('/{connection}', [PersonalMcpConnectionsController::class, 'destroy']);
            Route::put('/{connection}/tools/{tool}', [PersonalMcpConnectionsController::class, 'setTool']);
            Route::post('/{connection}/oauth', [McpOAuthController::class, 'begin']);
        });

        Route::post('api/conversations/mcp/interactions/{interaction}', [McpInteractionController::class, 'respond']);
    });
