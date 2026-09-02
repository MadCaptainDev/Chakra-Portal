<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate from whatsapp_sent_at: that column is staff choosing to hand a
 * client the actual PDF over WhatsApp; this one is the automated "your
 * report is ready" nudge (NotifyReportsReady) that only ever says so and
 * links back to the portal -- never the document itself. One row can carry
 * both, at different times, for different reasons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_report_notes', function (Blueprint $table) {
            $table->timestamp('ready_notified_at')->nullable()->after('whatsapp_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_report_notes', function (Blueprint $table) {
            $table->dropColumn('ready_notified_at');
        });
    }
};
