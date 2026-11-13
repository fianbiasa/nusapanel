<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: what a hosting account used of the server on one
     * day, from its cgroups: CPU time in milliseconds and the most memory
     * it held at once (MiB), sampled every five minutes.
     */
    public function up(): void
    {
        Schema::create('resource_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('cpu_ms')->default(0);
            $table->unsignedInteger('memory_peak_mb')->default(0);
            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_usages');
    }
};
