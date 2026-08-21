<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The real Instagram post a planned Notion item turned into.
 *
 * Notion says what was meant to go out and when; the Instagram cache says
 * what actually did and how it performed. Matching the two is what turns a
 * plan into plan-versus-actual -- otherwise "15 reels published" is a claim
 * nobody can check and carries no reach, views or likes with it.
 *
 * Stored rather than matched at read time: the join is by account and
 * calendar day and needs a tie-break when a client posted twice in a day,
 * which is work to redo on every page render. A stored link is also
 * correctable by hand later, which a computed one is not.
 *
 * nullOnDelete, not cascade: a re-synced or purged Instagram item must
 * unlink the planned row, never delete the record that the studio planned
 * and made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->foreignId('social_media_item_id')->nullable()->after('notion_url')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_media_item_id');
        });
    }
};
