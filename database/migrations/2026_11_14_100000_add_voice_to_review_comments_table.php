<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voice notes on a review: the recording (a WAV under
     * storage/app/private/reviews/voice), its length and waveform for the
     * player, and Gemini's transcript, which becomes the comment's body so a
     * spoken change still reads as a correction on the editor's card.
     */
    public function up(): void
    {
        Schema::table('review_comments', function (Blueprint $table) {
            $table->string('voice_path')->nullable()->after('body');
            $table->float('voice_seconds')->nullable()->after('voice_path');
            $table->json('voice_peaks')->nullable()->after('voice_seconds');
            $table->string('voice_state', 16)->nullable()->after('voice_peaks'); // pending | done | failed
            $table->text('voice_english')->nullable()->after('voice_state');
        });
    }

    public function down(): void
    {
        Schema::table('review_comments', function (Blueprint $table) {
            $table->dropColumn(['voice_path', 'voice_seconds', 'voice_peaks', 'voice_state', 'voice_english']);
        });
    }
};
