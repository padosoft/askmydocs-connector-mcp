<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_pending_interactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_connection_id')->constrained('mcp_connector_connections')->cascadeOnDelete();
            $table->string('actor_type', 191);
            $table->string('actor_id', 191);
            $table->string('conversation_id', 191);
            $table->string('kind', 24);
            $table->text('continuation');
            $table->json('prompt_json')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'actor_type', 'actor_id', 'conversation_id'],
                'idx_mcp_pending_actor_conversation',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_pending_interactions');
    }
};
