<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: whose template a record is. Null is the
     * administrators' template; a reseller's own records replace it for
     * the zones of the reseller's accounts.
     */
    public function up(): void
    {
        Schema::table('dns_template_records', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dns_template_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
