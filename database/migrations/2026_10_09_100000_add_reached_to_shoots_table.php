<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the crew got to the location, and who said so.
 *
 * Its own pair of columns rather than folded into started_at: "we're here"
 * and "we're rolling" are often an hour apart -- setting up lights, waiting
 * on the client -- and that gap is exactly what a producer chasing a late
 * shoot wants to see. Timestamps, not a status, for the same reason as
 * add_run_tracking_to_shoots_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('reached_at')->nullable()->after('status');
            $table->foreignId('reached_by_id')->nullable()->after('reached_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropForeign(['reached_by_id']);
            $table->dropColumn(['reached_at', 'reached_by_id']);
        });
    }
};
