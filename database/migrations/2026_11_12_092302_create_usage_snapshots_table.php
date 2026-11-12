<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The disk space and inodes of each hosting account at the end of each
     * day (the last measurement of the day), for the usage graphs.
     */
    public function up(): void
    {
        Schema::create('usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('disk_kb')->default(0);
            $table->unsignedBigInteger('inodes')->default(0);
            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_snapshots');
    }
};
