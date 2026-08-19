<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default');
            $table->foreignId('mcp_connector_connection_id')
                ->constrained('mcp_connector_connections')
                ->cascadeOnDelete();
            $table->text('uri');
            $table->char('uri_hash', 64);
            $table->string('name')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('annotations_json')->nullable();
            $table->json('meta_json')->nullable();
            $table->boolean('enabled')->default(false);
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_ingested_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->json('ingest_error_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['mcp_connector_connection_id', 'uri_hash'],
                'uq_mcp_connector_resources_connection_uri',
            );
            $table->index(
                ['tenant_id', 'enabled', 'removed_at'],
                'idx_mcp_connector_resources_tenant_enabled',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_resources');
    }
};
