<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which model provider the assistant talks to.
 *
 * Added rather than assumed, because the studio's answer to "what does this
 * cost" is "nothing": Groq's free tier serves an open model at no charge,
 * where Anthropic bills per token. The column is what lets that be a choice
 * on a screen instead of a deploy -- and what lets a key be swapped the day a
 * free tier changes its terms.
 *
 * Defaults to groq for exactly that reason. Nobody should have to paste a
 * card to get an answer about their own invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('provider', 32)->default('groq')->after('api_key');
        });

        /*
         * The model column's default was written when Anthropic was the only
         * option. Blanking it lets AiSetting::modelName() fall through to
         * whichever default belongs to the chosen provider, rather than
         * sending Groq a Claude model id and getting a 404 nobody expects.
         */
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('model')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
