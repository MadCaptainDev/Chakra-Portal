<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set once SendShootReminders has pushed tomorrow's call sheet to a shoot's
 * crew -- the idempotency guard against a scheduler catch-up (or a manual
 * re-run) re-alerting everyone a second time for the same shoot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
