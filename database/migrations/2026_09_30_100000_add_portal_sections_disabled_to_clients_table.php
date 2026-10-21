<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Which client-portal sections are switched off for this client
            // (see Client::PORTAL_SECTIONS). Null means nobody has chosen yet,
            // and Client::PORTAL_SECTIONS_OFF_BY_DEFAULT answers instead, so
            // existing clients need no backfill.
            $table->json('portal_sections_disabled')->nullable()->after('report_sections_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('portal_sections_disabled');
        });
    }
};
