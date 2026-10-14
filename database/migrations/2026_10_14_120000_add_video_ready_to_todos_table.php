<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * My Editing's "Video ready": after review a video is ready with a time it
 * must be published at. ready_at says when it was approved, publish_at when
 * it goes out, publish_reminded_at that the "not published yet" push (30
 * minutes after publish_at) has been sent, so it is sent once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable();
            $table->dateTime('publish_at')->nullable();
            $table->timestamp('publish_reminded_at')->nullable();
            $table->index(['publish_at', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['publish_at', 'posted_at']);
            $table->dropColumn(['ready_at', 'publish_at', 'publish_reminded_at']);
        });
    }
};
