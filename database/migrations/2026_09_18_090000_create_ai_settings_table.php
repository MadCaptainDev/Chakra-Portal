<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio's Anthropic credentials. One row, like notion_settings.
 *
 * The key is encrypted rather than hashed for the same reason every other
 * credential here is: every call has to present it, so it must read back.
 *
 * `is_active` is a kill switch with teeth, and it is deliberately separate
 * from "is a key present". A key can stay on file while the assistant is
 * switched off -- which is what somebody reaches for when it says something
 * odd on a Saturday, and they do not want to also lose the key and have to
 * find it again in the Anthropic console on Monday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->text('api_key')->nullable();
            $table->string('model')->default('claude-opus-5');
            $table->boolean('is_active')->default(false);
            /*
             * A ceiling, not a budget: it exists so a loop nobody predicted
             * costs one day's cap rather than a month's bill. Counted per
             * calendar day in the app's own timezone, against answers
             * actually attempted.
             */
            $table->unsignedInteger('daily_answer_limit')->default(100);
            $table->timestamp('last_answered_at')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
