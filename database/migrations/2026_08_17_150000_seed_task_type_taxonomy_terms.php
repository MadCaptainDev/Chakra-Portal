<?php

use App\Models\TaxonomyTerm;
use App\Models\TimesheetEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the timesheet's task types out of a constant and into master data.
 *
 * No column changes and no data migration, and that is the point: entries
 * store the SLUG -- 'editing', 'shooting' -- and these terms are created with
 * exactly those slugs. Fifteen hundred existing rows keep pointing at the same
 * values, and the only thing that changes is where the list of options comes
 * from.
 *
 * That also fixes the slug in place as the contract. Renaming "Posting" to
 * "Publishing" is safe and relabels every past entry; changing its slug would
 * orphan the hours logged against it, which is why the master-data screen
 * derives the slug from the name only on creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sort = 0;

        foreach (TimesheetEntry::TASK_TYPES as $slug => $name) {
            // updateOrCreate on the slug: this migration must be safe to run
            // against a database where somebody has already added them by hand.
            TaxonomyTerm::updateOrCreate(
                ['type' => TaxonomyTerm::TYPE_TASK_TYPE, 'slug' => $slug],
                ['name' => $name, 'is_active' => true, 'sort_order' => $sort += 10],
            );
        }
    }

    public function down(): void
    {
        // Only the seeded four. Anything the studio added afterwards is theirs
        // and is left alone -- and the entries pointing at it would be orphaned
        // by deleting it anyway.
        DB::table('taxonomy_terms')
            ->where('type', TaxonomyTerm::TYPE_TASK_TYPE)
            ->whereIn('slug', array_keys(TimesheetEntry::TASK_TYPES))
            ->delete();
    }
};
