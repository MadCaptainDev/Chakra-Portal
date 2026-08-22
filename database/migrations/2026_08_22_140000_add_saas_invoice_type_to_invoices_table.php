<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every App Studio invoice is AMC -- a one-off build/dev-work invoice
 * is App Studio income too, but must NOT extend a product's amc_paid_until
 * when it's paid (see Invoice::recalculateStatus()). This column is what
 * tells the two apart; meaningless (and left null) on any invoice with no
 * saas_product_id at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('saas_invoice_type', 20)->nullable()->after('saas_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('saas_invoice_type');
        });
    }
};
