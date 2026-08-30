<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_items', function (Blueprint $table) {
            $table->string('quantity', 64)->default('1')->change();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(1)->change();
        });
    }
};
