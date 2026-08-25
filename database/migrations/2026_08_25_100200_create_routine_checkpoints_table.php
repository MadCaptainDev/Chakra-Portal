<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named steps within a routine (e.g. DMs, Comments). Zero rows means the
 * generator uses one implicit checkpoint (checkpoint_id null on occurrences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['routine_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_checkpoints');
    }
};
