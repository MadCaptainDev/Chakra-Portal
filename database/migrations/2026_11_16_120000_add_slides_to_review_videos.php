<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram picture posts go through review too: a review "video" can be
 * one picture or a carousel. slides = [{path, w, h}]; a comment on a slide
 * keeps the slide's index (0-based) in at_seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_videos', function (Blueprint $table) {
            $table->json('slides')->nullable()->after('poster_path');
        });
    }

    public function down(): void
    {
        Schema::table('review_videos', fn (Blueprint $t) => $t->dropColumn('slides'));
    }
};
