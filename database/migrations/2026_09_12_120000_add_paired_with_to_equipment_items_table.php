<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A battery only ever goes out with its own camera -- the picker asking
 * for it as a second, unrelated decision is exactly the "no need to pick
 * that separately" the studio flagged. This records that relationship on
 * the accessory ("A6400 Battery" points at "Sony A6400"), not the other
 * way round, so one camera can have several paired accessories without a
 * pivot table: a battery, a charger and a cage all just point at the same
 * camera row.
 *
 * Self-referencing and nullable -- most items (tripods, ND filters, lights)
 * pair with nothing and keep working exactly as they do today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->foreignId('paired_with_id')->nullable()->after('category_id')
                ->constrained('equipment_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paired_with_id');
        });
    }
};
