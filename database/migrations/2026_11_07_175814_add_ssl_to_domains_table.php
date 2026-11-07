<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('ssl_status', 16)->nullable()->after('status');
            $table->json('ssl_names')->nullable()->after('ssl_status');
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_names');
            $table->text('ssl_error')->nullable()->after('ssl_expires_at');
            $table->boolean('force_https')->default(false)->after('ssl_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['ssl_status', 'ssl_names', 'ssl_expires_at', 'ssl_error', 'force_https']);
        });
    }
};
