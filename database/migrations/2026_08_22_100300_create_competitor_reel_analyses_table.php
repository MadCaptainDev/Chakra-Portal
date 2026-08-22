<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemini's shot-by-shot breakdown of one competitor reel.
 *
 * One row per reel (unique competitor_reel_id) -- re-analyzing overwrites
 * rather than accumulating, since the video itself does not change and a
 * second breakdown of the same footage is not a second fact worth keeping.
 *
 * Written only from app/Console/Commands/AnalyzeCompetitorReels.php: the
 * upload-and-poll-until-ACTIVE step this depends on can take up to two
 * minutes per video, which is not safe inside a web request on a host with
 * no queue worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_reel_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_reel_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('breakdown');
            $table->string('gemini_model');
            $table->timestamp('analyzed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_reel_analyses');
    }
};
