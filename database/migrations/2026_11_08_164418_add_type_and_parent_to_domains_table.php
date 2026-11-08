<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Subdomains and domain pointers are websites of their own, so they are
     * rows of `domains` too: `type` tells them apart, `parent_id` is the
     * domain they belong to, and `pointer_mode` says whether a pointer shows
     * that domain's website or redirects to it. Existing rows are domains.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('user_id')->constrained('domains')->cascadeOnDelete();
            $table->string('type', 16)->default('domain')->after('name');
            $table->string('pointer_mode', 16)->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['type', 'pointer_mode']);
        });
    }
};
