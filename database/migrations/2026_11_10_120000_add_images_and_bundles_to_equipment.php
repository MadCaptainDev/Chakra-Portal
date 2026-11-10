<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A picture for each kit item, and "what goes with it".
 *
 * paired_with_id (2026_09_12) let an accessory name the one item it travels
 * with. That cannot say "the FX30 takes the power zoom lens AND a 256GB
 * card", or "two batteries", or let one card go with two cameras -- so the
 * relation moves to a table of its own, parent -> child with a quantity.
 * The old pairs are copied in; the column stays, unread, so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            // Relative to public/, written by PublicUpload.
            $table->string('image_path')->nullable();
        });

        Schema::create('equipment_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->foreignId('child_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['parent_item_id', 'child_item_id']);
        });

        $pairs = DB::table('equipment_items')->whereNotNull('paired_with_id')->get(['id', 'paired_with_id']);

        foreach ($pairs as $pair) {
            DB::table('equipment_bundle_items')->insert([
                'parent_item_id' => $pair->paired_with_id,
                'child_item_id' => $pair->id,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_bundle_items');
        Schema::table('equipment_items', fn (Blueprint $table) => $table->dropColumn('image_path'));
    }
};
