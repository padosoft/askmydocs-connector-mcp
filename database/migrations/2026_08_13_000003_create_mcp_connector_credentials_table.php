<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_connection_id')
                ->unique()
                ->constrained('mcp_connector_connections')
                ->cascadeOnDelete();
            $table->string('credential_type', 16)->default('oauth');
            $table->text('bearer_token')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('token_type', 32)->default('Bearer');
            $table->text('issuer')->nullable();
            $table->text('resource')->nullable();
            $table->json('scopes_json')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('rotation_version')->default(0);
            $table->timestamps();

            $table->index('tenant_id', 'idx_mcp_connector_credentials_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_credentials');
    }
};
