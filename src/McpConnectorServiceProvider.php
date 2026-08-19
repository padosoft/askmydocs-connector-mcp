<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp;

use Illuminate\Support\ServiceProvider;
use Padosoft\AskMyDocsConnectorMcp\Contracts\SafeHttpClientContract;
use Padosoft\AskMyDocsConnectorMcp\Services\McpCredentialVault;
use Padosoft\AskMyDocsConnectorMcp\Services\SafeHttpClient;
use Padosoft\AskMyDocsMcpPack\Artifacts\FlysystemArtifactManager;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;

final class McpConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/connector-mcp.php',
            'connector-mcp',
        );

        $this->app->singleton(McpCredentialVault::class);
        $this->app->singleton(SafeHttpClientContract::class, SafeHttpClient::class);
        if (! $this->app->bound(ArtifactManagerContract::class)) {
            $this->app->singleton(ArtifactManagerContract::class, FlysystemArtifactManager::class);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'connector-mcp');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/connector-mcp.php' => config_path('connector-mcp.php'),
        ], 'connector-mcp-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'connector-mcp-migrations');
    }
}
