<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the moment a log row's SendWhatsappCampaignMessage job was actually
 * queued, separate from status.
 *
 * whatsapp:dispatch-campaigns runs every minute, but a queued job can still
 * be sitting in the queue (not yet run) when the next tick fires -- its log
 * is still `pending` at that point, since only the job itself flips it to
 * sent/failed. Without this column, that second tick would see the same
 * still-pending row and dispatch a duplicate job for it, double-messaging
 * the contact. dispatched_at is stamped by the command at dispatch time,
 * before the job ever runs, so the next tick's `whereNull('dispatched_at')`
 * excludes it regardless of how long the job waits in queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->timestamp('dispatched_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->dropColumn('dispatched_at');
        });
    }
};
