<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency guard for the depletion-alert command, same shape as
 * Shoot.reminder_sent_at / MonthlyReportNote.ready_notified_at: a plain
 * "already sent" timestamp would fire once and never again even as the
 * projected depletion date kept moving closer, so this also remembers
 * WHICH depletion date was last alerted on -- a re-run only re-fires when
 * the computed date has moved earlier than what's stored, or nothing has
 * been sent yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->date('forecast_alert_depletion_date')->nullable()->after('industry_id');
            $table->timestamp('forecast_alert_sent_at')->nullable()->after('forecast_alert_depletion_date');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['forecast_alert_depletion_date', 'forecast_alert_sent_at']);
        });
    }
};
