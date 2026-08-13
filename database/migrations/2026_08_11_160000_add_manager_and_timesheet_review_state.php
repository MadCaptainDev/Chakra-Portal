<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who reports to whom, and what a manager decided about an entry.
 *
 * manager_id is nullable and self-referential. Everyone senior enough has no
 * manager, and nullOnDelete rather than cascade because deleting a manager's
 * login must not delete their team -- it should leave the team unmanaged and
 * visible, which is a problem someone can see and fix.
 *
 * review_state replaces the earlier arrangement where approval was inferred
 * from "reviewed_at set and no question asked". That could say approved and
 * queried but never rejected, and a rejection is the thing a manager most needs
 * to be able to say. The three states are now explicit and the note carries the
 * why.
 *
 * was_backdated is stamped at creation and never recomputed. Whether an entry
 * was filed late is a fact about the moment it was written; deriving it later
 * from worked_on against today would relabel every entry in the system as
 * backdated the following morning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('role')
                ->constrained('users')->nullOnDelete();

            // "Show me my team" runs on every manager's timesheet screen.
            $table->index('manager_id');
        });

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->string('review_state', 20)->nullable()->after('review_note');
            $table->boolean('was_backdated')->default(false)->after('review_state');

            // The approval queue: everything waiting on a decision.
            $table->index(['review_state', 'worked_on']);
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropIndex(['review_state', 'worked_on']);
            $table->dropColumn(['review_state', 'was_backdated']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
