<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture fields on complete (counts, notes, toggles). checkpoint_id null
 * means the field applies to every checkpoint / the implicit one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained('routine_checkpoints')->cascadeOnDelete();
            $table->string('label');
            $table->string('key', 64);
            // number | text | boolean
            $table->string('type', 20)->default('number');
            $table->string('default_value')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['routine_id', 'key'], 'routine_fields_key_unique');
            $table->index(['routine_id', 'checkpoint_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_fields');
    }
};
