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
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->change();
            $table->string('status')->default('unpaid')->after('total');
            $table->date('due_date')->nullable()->after('invoice_date');
            $table->unsignedBigInteger('recurring_invoice_id')->nullable()->after('created_by');
            $table->timestamp('approved_at')->nullable()->after('status');

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'due_date', 'recurring_invoice_id', 'approved_at']);
            $table->string('invoice_number')->nullable(false)->change();
        });
    }
};
