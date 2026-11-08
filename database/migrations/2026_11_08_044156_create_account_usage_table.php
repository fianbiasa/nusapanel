<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The disk usage of each hosting account as the server last measured
     * it. Accounts that were never measured have no row.
     */
    public function up(): void
    {
        Schema::create('account_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('disk_kb');
            $table->unsignedBigInteger('inodes');
            $table->timestamp('measured_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_usage');
    }
};
