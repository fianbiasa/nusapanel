<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the CPU counter of each cgroup of the hosting
     * accounts as last read, by the cgroup's id, so the next reading adds
     * only what was used since.
     */
    public function up(): void
    {
        Schema::create('resource_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('cgroup_id');
            $table->unsignedBigInteger('cpu_usec');
            $table->unique(['user_id', 'cgroup_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_counters');
    }
};
