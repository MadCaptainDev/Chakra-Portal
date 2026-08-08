<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work category for each logged entry: shooting, editing, or other.
     *
     * Existing rows are backfilled from the task name (Shoot → shooting,
     * Edit → editing, else other) so charts and filters stay meaningful.
     */
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->string('task_type', 20)->default('other')->after('task');
            $table->index('task_type');
        });

        // Heuristic backfill — mirrors TimesheetEntry::inferTaskType().
        DB::table('timesheet_entries')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $task = mb_strtolower((string) ($row->task ?? ''));
                $type = 'other';

                if (preg_match('/\b(shoot|shooting|photo\s*shoot)\b/u', $task)) {
                    $type = 'shooting';
                } elseif (preg_match('/\b(edit|editing|edits)\b/u', $task)) {
                    $type = 'editing';
                }

                DB::table('timesheet_entries')
                    ->where('id', $row->id)
                    ->update(['task_type' => $type]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropIndex(['task_type']);
            $table->dropColumn('task_type');
        });
    }
};
