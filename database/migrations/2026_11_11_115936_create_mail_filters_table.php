<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mailbox's filters, run in the order they were made: when the field
     * (from, to, subject) contains or is the value, the mail is moved to a
     * folder, forwarded (a copy stays) or deleted. They become part of the
     * mailbox's Sieve script.
     */
    public function up(): void
    {
        Schema::create('mail_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('field', 16);
            $table->string('operator', 16);
            $table->string('value', 200);
            $table->string('action', 16);
            $table->string('target', 254)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_filters');
    }
};
