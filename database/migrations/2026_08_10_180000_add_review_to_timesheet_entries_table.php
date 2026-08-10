<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager review of a timesheet entry.
 *
 * Kept in its own columns rather than folded into `status`. Status belongs to
 * the person who did the work -- they mark an entry completed, pending or
 * cancelled -- and an employee editing their own entry must not be able to
 * quietly undo a manager's decision, nor a manager's approval erase what the
 * employee said about their own work. Two owners, two fields.
 *
 * review_note carries a manager's question back to the employee. An entry with
 * a note and no approval is "queried": the employee has something to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('notes');
            $table->foreignId('reviewed_by_id')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable()->after('reviewed_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['reviewed_at', 'review_note']);
        });
    }
};
