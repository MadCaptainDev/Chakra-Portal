<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change ever made to a to-do, with the time it happened.
 *
 * This is the point of the feature rather than a nicety: "for each and every
 * update, time should be noted as per the day". A status column alone says
 * where something is, never when it got there, so a day nobody touched reads
 * exactly like a day of work.
 *
 * There is no logged_on column. It would always equal date(created_at), and a
 * column that is a pure function of another column drifts the first time a code
 * path forgets to set it. The screens group by day in PHP, the way the
 * timesheet already groups its entries -- and config/app.php is Asia/Kolkata,
 * so created_at dates are local dates and the grouping is the one a person
 * expects.
 *
 * from_on / to_on carry the dates a "moved" row shifted between, so the
 * timeline can say "moved from Wed 12th to Thu 13th" rather than just "moved".
 * Counting these rows is also how the screens know a to-do has slipped three
 * times; nothing denormalises that count.
 *
 * user_id is nullable and nullOnDelete, matching timesheet_days.reviewed_by_id.
 * Removing a person must not take the record of what they did with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 20);

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            $table->date('from_on')->nullable();
            $table->date('to_on')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            // The timeline reads one to-do's history oldest-first.
            $table->index(['todo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_updates');
    }
};
