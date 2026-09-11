<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('quotation_prefix')->default('QT-')->after('invoice_prefix');
        });

        // Existing row (CompanySetting::current() firstOrCreate's id=1) predates
        // the column default applying -- backfill it explicitly.
        DB::table('company_settings')->update(['quotation_prefix' => 'QT-']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('quotation_prefix');
        });
    }
};
