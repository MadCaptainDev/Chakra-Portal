<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Did they see the call time?" -- a question the producer currently answers
 * by ringing round. Crew confirm on WhatsApp (see App\Support\CrewPortal),
 * and this is where that lands.
 *
 * Nullable with no default, and never written by the crew form: an
 * unconfirmed row is the normal state of a call time that has only just been
 * set, not an error, and "not confirmed yet" must stay distinguishable from
 * "confirmed at some unknown time".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_crew', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('call_time');
        });
    }

    public function down(): void
    {
        Schema::table('shoot_crew', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
