<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_connections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_server_id')
                ->constrained('mcp_connector_servers')
                ->cascadeOnDelete();
            $table->string('mode', 16);
            $table->nullableMorphs('owner', 'idx_mcp_connector_connections_owner');
            $table->string('label', 100)->default('default');
            $table->string('project_key', 100)->nullable();
            $table->json('granted_scopes_json')->nullable();
            $table->json('account_metadata_json')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('last_authorized_at')->nullable();
            $table->timestamp('last_discovered_at')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'owner_type', 'owner_id', 'status'],
                'idx_mcp_connector_connections_owner_status',
            );
            $table->index(
                ['tenant_id', 'project_key', 'status'],
                'idx_mcp_connector_connections_project_status',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_connections');
    }
};
