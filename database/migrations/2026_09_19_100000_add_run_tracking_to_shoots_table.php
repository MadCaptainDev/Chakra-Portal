<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a shoot actually started and stopped, as distinct from what it was
 * booked as.
 *
 * Deliberately timestamps rather than a fifth Shoot::STATUS_* value. "In
 * progress" is a fact about the clock, not a booking state -- and the four
 * existing statuses are switched on by the badge helper, the calendar, the
 * Notion status map and every report, none of which has an answer for a
 * shoot that is happening right now. Two nullable columns say the same thing
 * without asking any of them to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->foreignId('started_by_id')->nullable()->after('finished_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropForeign(['started_by_id']);
            $table->dropColumn(['started_at', 'finished_at', 'started_by_id']);
        });
    }
};
