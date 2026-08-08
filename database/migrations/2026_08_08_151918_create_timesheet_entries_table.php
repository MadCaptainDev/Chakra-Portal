<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One logged piece of work.
     *
     * `minutes` is the source of truth for duration, not the clock times.
     * The team's old spreadsheet recorded 12-hour times with no AM/PM, so
     * "06:00 to 10:30" was really 16h30m -- unrecoverable from the times
     * alone. Entries are also legitimately logged with a duration but no end
     * time. The form submits 24-hour values and derives minutes when it can.
     */
    public function up(): void
    {
        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('worked_on');
            $table->string('task');
            $table->string('venture')->nullable();
            $table->time('started_at')->nullable();
            $table->time('ended_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'worked_on']);
            $table->index('worked_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_entries');
    }
};
