<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The name the public website shows for a client, when it should differ
 * from the name on the books. "Digital Harvest (Janet Hospitals)" is who
 * the invoices are made out to; visitors know the brand as "Janet
 * Hospitals Trichy". Null means "the same as name".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('display_name', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
