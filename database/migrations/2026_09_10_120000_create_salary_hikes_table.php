<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A salary raise (or cut) applied to an employee (Expense, type=salary),
     * kept as its own audit trail rather than only overwriting
     * expenses.amount -- so "what did this person earn in March" stays
     * answerable after a later hike, the same reason expense_payments is
     * its own table instead of a single running total.
     */
    public function up(): void
    {
        Schema::create('salary_hikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->decimal('previous_amount', 12, 2);
            $table->decimal('new_amount', 12, 2);
            $table->date('effective_on');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['expense_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_hikes');
    }
};
