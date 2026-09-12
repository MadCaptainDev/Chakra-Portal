<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatically-earned recognition, kept deliberately apart from
 * employee_points.
 *
 * employee_points is one row per person per month holding a score an admin
 * decided. That is a judgement, and a nightly job must never add to it,
 * round it up, or overwrite it -- the number has to keep meaning "what a
 * human thought", or it stops being worth awarding. So this is a separate
 * ledger: one row per thing actually observed, with the manual score left
 * entirely alone beside it.
 *
 * `source_key` is what makes the job safe to run twice. Every award names
 * the exact fact it came from ("timesheet:2026-09-12", "content:1481"), and
 * the unique index means a re-run updates nothing and inserts nothing. It is
 * a non-null string precisely so the index bites -- MySQL lets duplicate
 * NULLs through a unique index, which would have made a nullable reference
 * column no protection at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_recognitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('source_key', 120);
            $table->date('earned_on');
            $table->unsignedSmallInteger('points')->default(1);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_key']);
            $table->index(['user_id', 'earned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_recognitions');
    }
};
