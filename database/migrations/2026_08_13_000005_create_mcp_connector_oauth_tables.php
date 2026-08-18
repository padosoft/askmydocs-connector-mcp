<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default');
            $table->string('strategy', 24);
            $table->text('issuer');
            $table->char('issuer_hash', 64);
            $table->text('client_id');
            $table->text('client_secret')->nullable();
            $table->json('registration_metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'issuer_hash'], 'uq_mcp_connector_oauth_client_issuer');
        });

        Schema::create('mcp_connector_oauth_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_connection_id')->constrained('mcp_connector_connections')->cascadeOnDelete();
            $table->string('owner_type', 191);
            $table->string('owner_id', 191);
            $table->char('state_hash', 64)->unique();
            $table->text('pkce_verifier');
            $table->text('issuer');
            $table->text('resource');
            $table->text('authorization_endpoint');
            $table->text('token_endpoint');
            $table->text('client_id');
            $table->text('client_secret')->nullable();
            $table->boolean('authorization_response_iss_parameter_supported')->default(false);
            $table->json('scopes_json')->nullable();
            $table->text('ui_destination')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'mcp_connector_connection_id'], 'idx_mcp_oauth_attempt_connection');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_oauth_attempts');
        Schema::dropIfExists('mcp_connector_oauth_clients');
    }
};
