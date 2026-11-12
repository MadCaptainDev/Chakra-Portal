<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A monthly spend limit on Google Gemini, and the ledger it is measured
 * against: one row per Gemini call, with the tokens Google reported and
 * what they cost at its published prices. See App\Services\Ai\GeminiBudget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('model', 80);
            // What it was for: transcribe, captions, competitor... "other" when unsaid.
            $table->string('feature', 40)->default('other');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('audio_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['provider', 'created_at']);
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->decimal('gemini_monthly_cap_usd', 8, 2)->default(5.00);
            // "2026-11:80" -- the last warning told, so each is told once a month.
            $table->string('gemini_cap_alerted', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', fn (Blueprint $table) => $table->dropColumn(['gemini_monthly_cap_usd', 'gemini_cap_alerted']));
        Schema::dropIfExists('ai_usage');
    }
};
