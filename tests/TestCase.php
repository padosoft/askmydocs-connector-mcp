<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorMcp\McpConnectorServiceProvider;
use Padosoft\AskMyDocsMcpPack\AskMyDocsMcpPackServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConnectorServiceProvider::class,
            AskMyDocsMcpPackServiceProvider::class,
            McpConnectorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('mcp-pack.admin.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->run();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
