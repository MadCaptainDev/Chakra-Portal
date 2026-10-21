<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Video Checker is gone (removed with the Routines screens); its history
 * table had no rows in production when it went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('video_checks');
    }

    public function down(): void
    {
        Schema::create('video_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('preset', 20);
            $table->string('verdict', 10);
            $table->json('results');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }
};
