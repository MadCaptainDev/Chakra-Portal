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
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->text('intro_text')->nullable();
            $table->string('discount_label')->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            // draft -> accepted/rejected, same string-status convention as
            // Invoice rather than a DB enum -- see Quotation::STATUS_*.
            $table->string('status')->default('draft');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            // Set once a staff member turns this quotation into a real
            // Invoice (Quotation::convertToInvoice()). Left on the quotation
            // rather than a converted_at flag alone so the show page can
            // link straight through to the invoice it became.
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
