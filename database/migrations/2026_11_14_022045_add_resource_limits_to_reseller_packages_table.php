<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the most CPU, memory, processes and disk IO
     * priority a reseller may give each of its accounts. Null is no cap,
     * so existing reseller packages cap nothing.
     */
    public function up(): void
    {
        Schema::table('reseller_packages', function (Blueprint $table) {
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
        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->dropColumn(['cpu_percent', 'memory_mb', 'max_processes', 'io_weight']);
        });
    }
};
