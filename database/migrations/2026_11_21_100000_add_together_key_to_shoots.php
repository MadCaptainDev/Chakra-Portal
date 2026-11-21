<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shoots that happen together -- same place, same time, two brands (SVA Mart
 * and SVA Silks): they share a together_key. See Shoot::partners().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->string('together_key', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', fn (Blueprint $t) => $t->dropColumn('together_key'));
    }
};
