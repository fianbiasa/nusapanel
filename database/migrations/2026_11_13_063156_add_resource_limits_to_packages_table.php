<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the CPU, memory, process and disk IO limits a
     * package puts on its accounts, enforced by cgroups on the server. Null
     * is unlimited (for io_weight: the server's default share), so existing
     * packages limit nothing. Adding columns never rebuilds the table, so
     * the views on it stay.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('cpu_percent')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('max_processes')->nullable();
            $table->unsignedSmallInteger('io_weight')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['cpu_percent', 'memory_mb', 'max_processes', 'io_weight']);
        });
    }
};
