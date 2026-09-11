<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Same split as Invoice::saas_product_id/saas_invoice_type: which
            // side of Chakra this quotation is for, and (when it is App
            // Studio) whether it is quoting AMC or one-off development work.
            // Null/null for ordinary Chakra Production quotations.
            $table->foreignId('saas_product_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->string('saas_invoice_type')->nullable()->after('saas_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saas_product_id');
            $table->dropColumn('saas_invoice_type');
        });
    }
};
