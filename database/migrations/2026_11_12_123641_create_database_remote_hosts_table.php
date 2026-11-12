<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the hosts besides the server an account's database
     * users may connect from.
     */
    public function up(): void
    {
        Schema::create('database_remote_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('host', 64);
            $table->timestamps();

            $table->unique(['user_id', 'host']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_remote_hosts');
    }
};
