<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the error pages, hotlink protection, content
     * types and PHP extensions of a website. Adding nullable columns never
     * rebuilds the table, so the views on it stay.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->json('error_pages')->nullable();
            $table->json('hotlink')->nullable();
            $table->json('mime_types')->nullable();
            $table->json('php_extensions')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['error_pages', 'hotlink', 'mime_types', 'php_extensions']);
        });
    }
};
