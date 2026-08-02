<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the business pays out monthly, in one table.
     *
     * Two shapes share it:
     *  - EMIs / finance: fixed monthly amount, a start month and a finite
     *    installment count, so each one drops off the books by itself.
     *  - Salaries and bills: the same monthly amount every month with no end,
     *    until switched off.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('bill');       // emi | salary | bill
            $table->string('payee')->nullable();            // bank or vendor
            $table->decimal('amount', 12, 2);               // standard monthly amount
            $table->date('start_month')->nullable();        // EMI only, always day 1
            $table->unsignedSmallInteger('installments')->nullable(); // EMI only
            $table->boolean('is_active')->default(true);    // recurring only
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
