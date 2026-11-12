<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each website (domain, subdomain or pointer) moved on each day:
     * what it sent and the mail of its domain, in bytes.
     */
    public function up(): void
    {
        Schema::create('domain_bandwidths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unique(['domain_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_bandwidths');
    }
};
