<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations don't need the App Studio (AMC/development) split invoices
 * have -- turns out to just be noise on a plain quote. Dropping it rather
 * than leaving it unused, and adding `notes` instead: free-form bullet
 * points shown after the line items (scope notes, timeline, payment terms
 * -- whatever a specific quote needs to spell out), one per line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saas_product_id');
            $table->dropColumn('saas_invoice_type');
            $table->text('notes')->nullable()->after('intro_text');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('notes');
            $table->foreignId('saas_product_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->string('saas_invoice_type')->nullable()->after('saas_product_id');
        });
    }
};
