<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manager's decision about one person's day.
 *
 * The decision moved from the entry to the day because a day is the unit
 * people actually think in: "was Tuesday right?", not "was the third line of
 * Tuesday right?". A manager reading four entries and pressing approve four
 * times was doing the same job four times.
 *
 * The row only exists once somebody has decided. No row means the day is still
 * waiting, which is also true of a day nobody has looked at yet -- so the
 * absence of a decision and an undecided decision are the same state, and
 * cannot drift apart.
 *
 * The per-entry review columns on timesheet_entries are left in place and stop
 * being written. Dropping them would take the record of who approved what
 * before today with them, and this app's habit is to retire a column rather
 * than remove it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('worked_on');

            $table->string('review_state', 20);
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One decision per person per day; a second press updates the first.
            $table->unique(['user_id', 'worked_on']);

            // The manager's queue reads every decision for a month at once.
            $table->index(['worked_on', 'review_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_days');
    }
};
