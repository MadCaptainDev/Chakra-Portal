<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Active vs inactive -- a client the studio has stopped (or paused)
     * work for, kept on record rather than deleted. Defaults true so every
     * existing client is unaffected: nothing here was ever inactive until
     * someone says so.
     *
     * Separate from Client::CLIENT_TYPE (regular/occasion), which is about
     * what kind of work applies to a client, not whether the relationship
     * is current -- an occasion client from a one-off wedding shoot two
     * years ago is exactly as "inactive" a case as a regular client who
     * churned, and the two axes should not be conflated into one column.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('client_type');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
