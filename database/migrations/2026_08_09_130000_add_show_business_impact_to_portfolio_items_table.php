<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the money side of a case study is fit for the public page.
     *
     * Reach and engagement are the client's own published numbers, but sales,
     * orders and return on spend often are not ours to show. Recording them
     * and publishing them are separate decisions, so this switch keeps the
     * figures on file while leaving them off the website.
     */
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->boolean('show_business_impact')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn('show_business_impact');
        });
    }
};
