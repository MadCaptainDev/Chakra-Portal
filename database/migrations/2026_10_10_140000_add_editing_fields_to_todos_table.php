<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the editors' "My Editing" screen needs on a to-do:
 *
 *  - parent_id: a correction is a to-do hanging off the video it corrects,
 *    so a video can show "2 corrections, 1 open" and not be done until they
 *    are;
 *  - content_item_id: the Reel Planner item it was picked from, when it was;
 *  - kind: what the finished video is for -- reel, post, story, or none;
 *  - posted_at / post_url / posted_by_id: when it went out, and where.
 *
 * Time spent Working is not stored: it is in the to-do's status history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('todos')->cascadeOnDelete();
            $table->foreignId('content_item_id')->nullable()->after('venture')->constrained('content_items')->nullOnDelete();
            $table->string('kind', 16)->nullable()->after('content_item_id');
            $table->timestamp('posted_at')->nullable();
            $table->string('post_url', 500)->nullable();
            $table->foreignId('posted_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['content_item_id']);
            $table->dropForeign(['posted_by_id']);
            $table->dropColumn(['parent_id', 'content_item_id', 'kind', 'posted_at', 'post_url', 'posted_by_id']);
        });
    }
};
