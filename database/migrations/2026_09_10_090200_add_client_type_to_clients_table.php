<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regular vs occasion clients -- see App\Models\Client's own doc block.
 * Default 'regular' on backfill: every existing client keeps being treated
 * exactly as today (targets, Forecast, monthly reports all apply), same
 * safe-default pattern as whatsapp_portal_enabled and
 * report_sections_disabled when those were added. Nothing is retroactively
 * reclassified -- a person marks a client occasion deliberately, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('client_type')->default('regular')->after('industry_id');
            $table->string('service_note')->nullable()->after('client_type');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['client_type', 'service_note']);
        });
    }
};
