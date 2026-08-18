<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default');
            $table->string('name', 100);
            $table->string('catalog_scope', 16)->default('tenant');
            $table->nullableMorphs('owner', 'idx_mcp_connector_servers_owner');
            $table->string('transport', 32)->default('auto');
            $table->string('auth_mode', 16)->default('none');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->text('legacy_headers_encrypted')->nullable();
            $table->json('oauth_metadata_json')->nullable();
            $table->string('negotiated_era', 16)->nullable();
            $table->string('negotiated_version', 32)->nullable();
            $table->json('capabilities_json')->nullable();
            $table->json('server_info_json')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('last_discovered_at')->nullable();
            $table->json('error_json')->nullable();
            $table->string('created_by', 191)->nullable();
            $table->string('legacy_reference', 191)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'endpoint_hash'], 'idx_mcp_connector_servers_endpoint');
            $table->index(['tenant_id', 'status'], 'idx_mcp_connector_servers_tenant_status');
            $table->unique(['tenant_id', 'legacy_reference'], 'uq_mcp_connector_servers_legacy');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_servers');
    }
};
