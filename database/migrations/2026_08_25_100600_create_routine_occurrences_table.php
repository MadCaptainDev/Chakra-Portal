<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One concrete duty: routine × checkpoint × subject × date (× assigned user
 * in individual mode).
 *
 * fingerprint is the real uniqueness key because MySQL/SQLite treat NULLs as
 * distinct in unique indexes — without it, "no checkpoint" rows would
 * duplicate on every generate pass.
 *
 * Open occurrences roll forward as overdue; generation still creates new
 * due dates. Skip is an admin action with a reason in note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained('routine_checkpoints')->nullOnDelete();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on');
            // open | done | skipped
            $table->string('status', 20)->default('open');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->json('values')->nullable();
            $table->text('note')->nullable();
            // routine|cp|type|id|user|due — see RoutineOccurrence::fingerprintFor()
            $table->string('fingerprint', 191)->unique();
            $table->timestamps();

            $table->index(['status', 'due_on']);
            $table->index(['assigned_user_id', 'status', 'due_on']);
            $table->index(['routine_id', 'due_on']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_occurrences');
    }
};
