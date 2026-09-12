<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a `scheduled` flow last actually ran.
 *
 * A column rather than a cache key, because this is the only thing standing
 * between "the morning briefing" and "the morning briefing, four times" --
 * and a cache on this host is flushable by anyone running an artisan command.
 * Something whose failure mode is messaging the whole team repeatedly wants
 * to survive that.
 *
 * Null for every flow that is not scheduled, and for a scheduled one that has
 * never come due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table) {
            $table->date('last_run_on')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table) {
            $table->dropColumn('last_run_on');
        });
    }
};
