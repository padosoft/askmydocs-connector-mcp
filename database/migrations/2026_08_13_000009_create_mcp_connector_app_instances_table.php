<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_app_instances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_connection_id')
                ->constrained('mcp_connector_connections')
                ->cascadeOnDelete();
            $table->foreignId('mcp_connector_tool_id')
                ->constrained('mcp_connector_tools')
                ->cascadeOnDelete();
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('conversation_id', 191);
            $table->text('resource_uri');
            $table->longText('tool_input');
            $table->longText('tool_result');
            $table->longText('resource_snapshot')->nullable();
            $table->longText('model_context')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(
                ['tenant_id', 'actor_type', 'actor_id', 'conversation_id'],
                'idx_mcp_app_instances_actor_conversation',
            );
            $table->index('expires_at', 'idx_mcp_app_instances_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_app_instances');
    }
};
