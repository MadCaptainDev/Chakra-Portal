<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manager's verdict on a finished to-do.
 *
 * Marking your own work done is a claim, not a fact. The timesheet already
 * knows this -- a day is decided by somebody else -- and a to-do that anybody
 * can tick off is a to-do nobody trusts the board of.
 *
 * The verdict lives on the row rather than in its own table, unlike
 * timesheet_days. A day is a bucket that has to be conjured up from the entries
 * inside it, so the decision needed somewhere to live; a to-do is already the
 * thing being decided about.
 *
 * Null review_state means nobody has looked yet, which is the same state as a
 * to-do nobody has finished -- so waiting and undecided cannot drift apart,
 * the same reasoning that keeps timesheet_days from storing a "pending" row.
 * Any status change clears all four columns: the verdict was about work in a
 * particular state, and it stops meaning anything the moment that changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->string('review_state', 20)->nullable()->after('status');
            $table->text('review_note')->nullable()->after('review_state');
            $table->timestamp('reviewed_at')->nullable()->after('review_note');
            $table->foreignId('reviewed_by_id')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();

            // "What is waiting on me?" across a whole team.
            $table->index(['review_state', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['review_state', 'due_on']);
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['review_state', 'review_note', 'reviewed_at']);
        });
    }
};
