<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Video Checker's history: which export was checked, by whom, for where,
 * and what it found. The video itself is never uploaded -- it is analysed in
 * the browser -- so this row is the only trace of it the portal keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What the editor called it -- "SVA kurti reel v3".
            $table->string('label', 120)->nullable();
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');

            // reel | short | youtube | status (VideoCheck::PRESETS).
            $table->string('preset', 20);
            // pass | warn | fail -- the worst of the individual checks.
            $table->string('verdict', 10);

            // [{status, title, detail}], exactly what the editor saw.
            $table->json('results');

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_checks');
    }
};
