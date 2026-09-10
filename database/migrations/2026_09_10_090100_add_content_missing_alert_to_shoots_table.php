<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency guard for SendMissingContentAlerts, same shape as
 * reminder_sent_at on this same table: a shoot marked completed with
 * nothing added to the Reel Planner yet gets pushed once, not every day
 * it stays that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('content_missing_alert_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn('content_missing_alert_sent_at');
        });
    }
};
