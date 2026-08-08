<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time / irregular company spends: a spent_on date and a category.
     * Not recurring — only appear in the month they were spent.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->date('spent_on')->nullable()->after('joined_on');
            $table->string('category')->nullable()->after('spent_on');
            $table->index('spent_on');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['spent_on']);
            $table->dropColumn(['spent_on', 'category']);
        });
    }
};
