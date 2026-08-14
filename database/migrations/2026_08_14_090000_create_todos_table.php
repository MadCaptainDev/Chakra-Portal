<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One piece of work somebody means to do, however many days it takes.
 *
 * The timesheet records what was done. This records what is going to be, which
 * is the question nobody could answer before: what is this person on today, and
 * is the three-day edit actually moving.
 *
 * A two or three day job is ONE row spanning a range, not a row per day. Split
 * into daily copies it stops being one piece of work -- you cannot say it
 * slipped, only that yesterday's copy was never ticked.
 *
 * starts_on is the day it lands on the board and never moves; it is what the
 * board is anchored to, so rewriting it would erase the item from the days it
 * was actually worked. due_on is the promise, and the only thing "move to next
 * day" touches. It is NOT NULL, defaulted to starts_on on create -- a nullable
 * due date puts a null branch into overdue checks, the defer handler and every
 * badge, for no gain.
 *
 * closed_on is the day it was completed or cancelled, and exists because the
 * board predicate filters on it. There is deliberately no closed_at or
 * started_at: those instants are already on the todo_updates row that made the
 * change, and a second copy of a fact is a fact that can drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            // Named notes, not details, because that is what the same field is
            // called on timesheet_entries.
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('waiting');

            $table->date('starts_on');
            $table->date('due_on');
            $table->date('closed_on')->nullable();

            $table->timestamps();

            // The board reads one person's day, and the tracker reads everyone's.
            $table->index(['user_id', 'starts_on']);
            $table->index('closed_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
