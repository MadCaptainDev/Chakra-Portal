<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one piece of "invoices need separating" Chakra App Studio actually
 * asked for: tag an invoice (or a recurring schedule) as billing for a
 * SaasProduct's AMC, so it can be filtered apart from production work on
 * the same Invoices screen everyone already uses. Nullable and
 * nullOnDelete -- almost every invoice has no SaaS product and losing the
 * product later must not take its billing history down with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('saas_product_id')->nullable()->after('recurring_invoice_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->foreignId('saas_product_id')->nullable()->after('client_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saas_product_id');
        });

        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saas_product_id');
        });
    }
};
