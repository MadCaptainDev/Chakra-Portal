<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Check from each other" -- a flat, chronological comment thread on a
 * script, so a writer and an editor (or anyone else with the `comment`
 * ability, already reserved on the scripts module and unused until now)
 * can leave notes without editing the script body itself. Deliberately
 * flat, not threaded: a script's comments are a review conversation, not a
 * forum, and a reply-to-a-reply tree is more structure than that needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['script_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_comments');
    }
};
