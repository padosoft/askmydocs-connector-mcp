<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_tools', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_connection_id')
                ->constrained('mcp_connector_connections')
                ->cascadeOnDelete();
            $table->string('remote_name', 255);
            $table->string('local_name', 255);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('input_schema_json');
            $table->json('output_schema_json')->nullable();
            $table->json('annotations_json')->nullable();
            $table->json('meta_json')->nullable();
            $table->string('risk', 16)->default('unknown');
            $table->string('policy', 16)->default('auto');
            $table->boolean('enabled')->default(false);
            $table->boolean('read_only')->nullable();
            $table->boolean('idempotent')->nullable();
            $table->boolean('destructive')->nullable();
            $table->boolean('confirmation_required')->default(true);
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['mcp_connector_connection_id', 'remote_name'],
                'uq_mcp_connector_tools_connection_remote',
            );
            $table->unique(
                ['mcp_connector_connection_id', 'local_name'],
                'uq_mcp_connector_tools_connection_local',
            );
            $table->index(
                ['tenant_id', 'enabled'],
                'idx_mcp_connector_tools_tenant_enabled',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_tools');
    }
};
