<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was actually paid against an expense in a given month.
     *
     * Rows are created lazily -- only once something is recorded -- so an
     * untouched month costs nothing and "no row" cleanly means "not paid yet".
     * amount_paid is stored separately from the expense's standard amount
     * because the two routinely differ (rent 7,500 budgeted, 3,500 paid).
     */
    public function up(): void
    {
        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->date('period');                    // always the 1st of the month
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            // One record per expense per month.
            $table->unique(['expense_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
    }
};
