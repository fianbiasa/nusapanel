<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each hosting account moved on each day, counted by the agent
     * from the logs: what its websites sent, its mail sent and received,
     * and its FTP logins' downloads and uploads, in bytes.
     */
    public function up(): void
    {
        Schema::create('bandwidth_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('web_bytes')->default(0);
            $table->unsignedBigInteger('mail_bytes')->default(0);
            $table->unsignedBigInteger('ftp_bytes')->default(0);
            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bandwidth_usages');
    }
};
