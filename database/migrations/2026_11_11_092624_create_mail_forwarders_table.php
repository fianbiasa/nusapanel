<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email forwarders: mail to <local_part>@<domain> goes on to the
     * addresses in destinations (comma separated, as Postfix reads them).
     * A forwarder may have the address of a mailbox, which then keeps a
     * copy.
     */
    public function up(): void
    {
        Schema::create('mail_forwarders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part', 64);
            $table->text('destinations');
            $table->timestamps();

            $table->unique(['mail_domain_id', 'local_part']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_forwarders');
    }
};
