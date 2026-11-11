<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of a website's two names its visitors end up on; every existing
     * website keeps serving both.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('www_mode', 8)->default('both')->after('force_https');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('www_mode');
        });
    }
};
