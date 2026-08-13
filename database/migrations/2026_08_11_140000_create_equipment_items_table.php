<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the studio owns.
 *
 * One row per kind of thing, with a quantity -- not one row per physical unit.
 * The studio owns one gimbal and a dozen batteries; per-unit rows would put
 * twelve checkboxes for batteries on a 420px phone, which is the screen this
 * whole feature has to work on. A quantity column carries both cases in one
 * shape, and availability becomes a subtraction rather than a set difference.
 *
 * identifier is the serial or asset tag, for the things worth naming. It is
 * deliberately free text and not unique: a studio labels its two identical
 * batteries "A" and "B" as often as it records a manufacturer's serial.
 *
 * is_active retires kit without deleting it. A camera sold last year must stay
 * on the shoots it went out on, or the history of those shoots becomes a lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->nullable()
                ->constrained('taxonomy_terms')->nullOnDelete();
            $table->string('identifier')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // The register lists live kit alphabetically, and so does every
            // picker that adds an item to a shoot.
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_items');
    }
};
