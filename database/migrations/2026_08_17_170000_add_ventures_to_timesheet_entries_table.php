<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One timesheet entry can name several ventures.
 *
 * A shared shoot, a day split between two SVA brands, an edit that served
 * three clients: the studio has been picking one and losing the rest, or
 * picking "All / Multiple Clients" and losing all of them.
 *
 * ADDITIVE ON PURPOSE. `venture` stays, and stays authoritative:
 *
 * - Fifteen hundred existing rows are untouched and still correct.
 * - Thirty-three files read `venture` -- the stats, the anomaly checks, the
 *   spreadsheet importer, the Notion sync, four MCP tools. None of them need
 *   to change, and none of their numbers move.
 * - The minutes on an entry are still counted ONCE, against `venture`. That is
 *   the conservative reading and it is deliberate: splitting three hours
 *   across two ventures, or counting three against each, both change what
 *   every historical report means, and that is a decision to take on purpose
 *   rather than as a side effect of a form gaining a checkbox.
 *
 * `ventures` is the full list including the primary, so a reader never has to
 * union two columns to answer "who was this for".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->json('ventures')->nullable()->after('venture');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropColumn('ventures');
        });
    }
};
