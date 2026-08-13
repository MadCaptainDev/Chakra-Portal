<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review moves from the entry to the day.
 *
 * The per-entry columns added a day ago asked a manager to decide about each
 * line separately. In practice nobody thinks that way: a manager reads what
 * somebody did on Tuesday and forms one view of Tuesday. Four entries meant
 * four decisions and four places to write the same comment, and the moment two
 * of them disagreed there was no answer to "was Tuesday signed off?".
 *
 * timesheet_days holds that one decision instead, so these columns have nothing
 * left to say and are dropped rather than left to rot. Only one row in
 * production ever carried a decision, so nothing of consequence is being thrown
 * away.
 *
 * was_backdated stays. It is not a review -- it records that an entry was filed
 * for a day already gone, which is a fact about the entry itself and still worth
 * showing a manager when they come to decide the day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropIndex(['review_state', 'worked_on']);
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['review_state', 'review_note', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('notes');
            $table->foreignId('reviewed_by_id')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable()->after('reviewed_by_id');
            $table->string('review_state', 20)->nullable()->after('review_note');

            $table->index(['review_state', 'worked_on']);
        });
    }
};
