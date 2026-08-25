<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A repeating obligation template. Occurrences are materialised from this
 * by RoutineOccurrenceGenerator; this row never records completion itself.
 *
 * schedule_type drives RoutineScheduler. catch_up_days caps how far back
 * generation looks when the clock jumps or the host was off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            // daily | every_n_days | weekdays | monthly
            $table->string('schedule_type', 32);
            // Used by every_n_days (N) and as day_of_month for monthly.
            $table->unsignedSmallInteger('schedule_interval')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            // shared = one occurrence for the team; individual = one per permitted user
            $table->string('completion_mode', 20)->default('shared');
            // Null = no subject fan-out. Otherwise the morph class key (e.g. instagram_account).
            $table->string('subject_type', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('catch_up_days')->default(31);
            $table->date('starts_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
