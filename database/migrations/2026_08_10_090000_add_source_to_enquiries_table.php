<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which page sent the lead, and what they say prompted them.
     *
     * The site carries no analytics and adding a third-party script is ruled
     * out, so without this there is no way to tell whether the case-study
     * screen earns enquiries or the landing page does all the work.
     *
     * Deliberately narrow, and consistent with this table's existing stance
     * that the IP is "kept for spam triage, not for tracking anyone":
     * `source` records the page a visitor came from -- one of a fixed set of
     * values, never free text off the query string -- and `prompted_by` is
     * whatever they chose to tell us. Neither identifies anybody.
     */
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('source', 32)->nullable();
            $table->string('prompted_by', 500)->nullable();

            // The inbox splits by source to compare the two paths.
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'prompted_by']);
        });
    }
};
