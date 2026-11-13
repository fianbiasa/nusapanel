<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: tickets from an account to whoever looks after
     * it, its reseller (reseller_id) or the administrators (null).
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('subject', 150);
            $table->string('status', 16)->default('open')->index();
            $table->string('priority', 16)->default('normal');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
