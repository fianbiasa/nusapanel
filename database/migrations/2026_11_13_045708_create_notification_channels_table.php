<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: where an account gets its alerts, by email or
     * Telegram, and which alerts.
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('target', 255);
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
