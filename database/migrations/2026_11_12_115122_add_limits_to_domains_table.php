<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the bandwidth (per calendar month) and disk limits
     * an account sets for one of its websites, the disk its folder used when
     * last measured, and which limit stopped it, if one did.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->unsignedInteger('bandwidth_limit_mb')->nullable();
            $table->unsignedInteger('disk_limit_mb')->nullable();
            $table->unsignedInteger('disk_used_mb')->nullable();
            $table->timestamp('disk_measured_at')->nullable();
            $table->string('limit_reached', 16)->nullable();
            $table->timestamp('limit_reached_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['bandwidth_limit_mb', 'disk_limit_mb', 'disk_used_mb', 'disk_measured_at', 'limit_reached', 'limit_reached_at']);
        });
    }
};
