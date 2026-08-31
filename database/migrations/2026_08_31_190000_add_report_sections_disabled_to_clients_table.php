<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Which report sections this client's reports leave out by
            // default (see Client::REPORT_SECTIONS) -- an array of keys,
            // null/empty meaning "everything on", which is exactly today's
            // behaviour, so every existing client needs no backfill.
            // Stored as *disabled* keys rather than *enabled* ones so that
            // "no row has ever set a preference" and "this client wants
            // everything" are the same value (empty), not two things a
            // reader has to reconcile.
            $table->json('report_sections_disabled')->nullable()->after('whatsapp_portal_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('report_sections_disabled');
        });
    }
};
