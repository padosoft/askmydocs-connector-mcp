<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_remote_tasks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_connection_id')
                ->constrained('mcp_connector_connections')
                ->cascadeOnDelete();
            $table->foreignId('mcp_connector_tool_id')
                ->nullable()
                ->constrained('mcp_connector_tools')
                ->nullOnDelete();
            $table->nullableMorphs('actor', 'idx_mcp_remote_tasks_actor');
            $table->string('conversation_id', 191);
            $table->uuid('invocation_id');
            $table->string('remote_tool_name', 191);
            $table->string('local_tool_name', 191);
            $table->text('remote_task_id');
            $table->char('remote_task_hash', 64);
            $table->string('status', 32)->default('working');
            $table->text('status_message')->nullable();
            $table->text('input_requests')->nullable();
            $table->text('request_state')->nullable();
            $table->text('result_payload')->nullable();
            $table->text('error_payload')->nullable();
            $table->json('last_poll_error_json')->nullable();
            $table->json('provenance_json')->nullable();
            $table->unsignedInteger('poll_interval_ms')->default(1000);
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('poll_lock_until')->nullable();
            $table->timestamp('remote_created_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['mcp_connector_connection_id', 'remote_task_hash'],
                'uq_mcp_remote_tasks_connection_remote',
            );
            $table->index(
                ['tenant_id', 'actor_type', 'actor_id', 'conversation_id'],
                'idx_mcp_remote_tasks_scope',
            );
            $table->index(
                ['tenant_id', 'status', 'next_poll_at'],
                'idx_mcp_remote_tasks_polling',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_remote_tasks');
    }
};
