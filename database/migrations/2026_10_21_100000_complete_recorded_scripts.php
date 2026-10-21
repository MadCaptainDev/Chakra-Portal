<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Scripts that were already shot -- a video recorded against them on a
 * shoot, or shot according to the Reel Planner -- but still sat in Draft.
 * A recorded script is Completed (Script::markRecorded()); archived ones
 * are left alone. Videos recorded with no shot day on the script give it
 * their earliest recording day.
 */
return new class extends Migration
{
    public function up(): void
    {
        $firstTake = DB::table('shoot_videos')->whereNotNull('script_id')
            ->selectRaw('script_id, MIN(created_at) as at')->groupBy('script_id')->pluck('at', 'script_id');

        DB::table('scripts')
            ->whereNotIn('status', ['completed', 'archived'])
            ->where(fn ($q) => $q->whereIn('id', $firstTake->keys())->orWhereNotNull('shot_at'))
            ->orderBy('id')
            ->get(['id', 'shot_at'])
            ->each(fn ($s) => DB::table('scripts')->where('id', $s->id)->update([
                'status' => 'completed',
                'shot_at' => $s->shot_at ?? $firstTake[$s->id] ?? null,
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        // A data correction; nothing to undo safely.
    }
};
