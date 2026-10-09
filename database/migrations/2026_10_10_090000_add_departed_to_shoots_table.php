<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the crew set off for the location, and who said so -- the step before
 * reached_at. The Studio Map shows the crew travelling from this moment
 * until they reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('departed_at')->nullable()->after('status');
            $table->foreignId('departed_by_id')->nullable()->after('departed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropForeign(['departed_by_id']);
            $table->dropColumn(['departed_at', 'departed_by_id']);
        });
    }
};
