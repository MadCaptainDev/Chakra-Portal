<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-person Dashboard layout: which widgets show, and in what order.
     *
     * Two columns, same reasoning behind each:
     *
     * dashboard_widgets_disabled stores *disabled* keys, not enabled ones --
     * same convention as clients.report_sections_disabled -- so "nobody has
     * ever touched this" and "show everything" are the same value (null),
     * and a widget added to the app later is on by default for everyone
     * instead of invisible until each person opts in.
     *
     * dashboard_widgets_order stores only the keys somebody has explicitly
     * reordered, not a full ranking -- null/missing keys fall back to
     * App\Support\DashboardLayout::DEFAULT_ORDER and any newly added widget
     * simply slots into its default position rather than needing every
     * existing preference row backfilled.
     *
     * On users rather than a new table: this is two small arrays of static
     * string keys defined in code, not references to rows that can be
     * deleted out from under a foreign key -- the reason
     * dashboard_content_widgets IS its own table (pinning real
     * ContentAccount ids) does not apply here.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('dashboard_widgets_disabled')->nullable()->after('bio');
            $table->json('dashboard_widgets_order')->nullable()->after('dashboard_widgets_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dashboard_widgets_disabled', 'dashboard_widgets_order']);
        });
    }
};
