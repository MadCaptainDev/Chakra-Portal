<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every call this app makes to a paid LLM API, one row per call -- not
 * because the app needs to replay them, but because "how much are we
 * spending on this" was asked outright and the only honest answer is to
 * record every call as it happens, not estimate after the fact.
 *
 * portfolio_item_id is nullable and cascades on delete: a usage log
 * documents money already spent, so deleting the portfolio piece it
 * produced content for must not silently delete the record that it cost
 * anything -- FK is nullOnDelete, not cascadeOnDelete, so the row survives
 * with the link cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();

            // What the call was for -- 'portfolio_creative', 'competitor_concept',
            // etc. Free-text on purpose: a new caller needs no migration to
            // start logging under a new label.
            $table->string('purpose');

            $table->string('model');
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');

            // Computed at write time from that model's price-per-token, so a
            // later price change never rewrites what a past call actually cost.
            $table->decimal('estimated_cost_usd', 10, 4);

            $table->foreignId('portfolio_item_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
