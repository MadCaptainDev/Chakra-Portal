<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kit's operational condition -- separate from `is_active` (which
 * means "retired: we no longer own this, or never will again"). A camera
 * sent for repair is still owned and coming back, which is a different
 * fact from "sold it" and needs a different word on screen. Default
 * 'available' so every item already in the register reads exactly as it
 * does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->string('status')->default('available')->after('is_active');
            $table->string('status_note')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_note']);
        });
    }
};
