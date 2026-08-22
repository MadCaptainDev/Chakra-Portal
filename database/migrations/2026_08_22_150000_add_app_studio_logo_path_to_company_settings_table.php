<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A separate logo for Chakra App Studio invoices -- nullable, since it is
 * uploaded once the studio actually has one; until then App Studio
 * invoices fall back to the ordinary company logo (see
 * CompanySetting::logoDataUriFor()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('app_studio_logo_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('app_studio_logo_path');
        });
    }
};
