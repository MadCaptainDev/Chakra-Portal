<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each client is, for the Studio Map's real map, and where the studio
 * itself is.
 *
 * location_source says who put the pin there: 'geocoded' (looked up from the
 * address by clients:geocode) or 'manual' (somebody placed it on the map). A
 * manual pin is never overwritten by a lookup. geocoded_address is the address
 * text the lookup used, so an edited address is noticed and looked up again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('location_source', 16)->nullable()->after('longitude');
            $table->string('geocoded_address', 500)->nullable()->after('location_source');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('map_latitude', 10, 7)->nullable();
            $table->decimal('map_longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_source', 'geocoded_address']);
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['map_latitude', 'map_longitude']);
        });
    }
};
