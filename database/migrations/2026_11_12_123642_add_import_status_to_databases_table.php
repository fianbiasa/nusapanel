<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: how the last import into a database went.
     */
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->string('import_status', 16)->nullable();
            $table->text('import_error')->nullable();
            $table->timestamp('imported_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn(['import_status', 'import_error', 'imported_at']);
        });
    }
};
