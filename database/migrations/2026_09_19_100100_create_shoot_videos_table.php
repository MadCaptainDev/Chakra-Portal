<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One video captured on one shoot, filed by the person holding the camera.
 *
 * position is kept rather than leaning on created_at: two videos logged in
 * the same second would otherwise have no defined order, and "video 3" is
 * how a crew member refers to it out loud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['shoot_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_videos');
    }
};
