<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_connector_connections', function (Blueprint $table): void {
            $table->char('catalog_hash', 64)->nullable()->after('last_discovered_at');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_connector_connections', function (Blueprint $table): void {
            $table->dropColumn('catalog_hash');
        });
    }
};
