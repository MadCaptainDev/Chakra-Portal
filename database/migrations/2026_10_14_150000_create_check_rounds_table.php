<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A round of the Comment & Message Checker: the morning or evening pass over
 * every account. Started with one tap (or by the first tick, auto_started),
 * ended by itself when the round's last tile is ticked -- so a round's time
 * is measured without anyone running a stopwatch. One per checker, round and
 * day: the duty is shared, so is its round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->string('round', 16); // morning | evening | daily
            $table->date('on_date');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->boolean('auto_started')->default(false);
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('replies')->default(0);
            $table->timestamps();
            $table->unique(['routine_id', 'round', 'on_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_rounds');
    }
};
