<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's logo, so a case study can credit the brand it was made for
 * with the brand's own mark rather than its name set in our typeface.
 *
 * Stored as a path relative to public/ ("uploads/clients/x.png"), the same
 * convention as every other browser-reachable upload here -- see PublicUpload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
