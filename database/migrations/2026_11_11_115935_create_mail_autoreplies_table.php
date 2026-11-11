<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mailbox's automatic reply: while enabled (and between the optional
     * dates, for a holiday), senders get the subject and body once every
     * interval_days. It becomes part of the mailbox's Sieve script.
     */
    public function up(): void
    {
        Schema::create('mail_autoreplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('subject', 150);
            $table->text('body');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('interval_days')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_autoreplies');
    }
};
