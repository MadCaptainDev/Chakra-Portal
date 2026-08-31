<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_report_notes', function (Blueprint $table) {
            // When this client's report for this month was last sent over
            // WhatsApp -- lives on the same one-row-per-client-per-month
            // record as the note, rather than a new table, since a report
            // send is already scoped to exactly the same (client, month)
            // pair this row exists for.
            $table->timestamp('whatsapp_sent_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_report_notes', function (Blueprint $table) {
            $table->dropColumn('whatsapp_sent_at');
        });
    }
};
