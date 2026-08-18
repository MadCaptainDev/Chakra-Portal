<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follower demographics for a connected Instagram account -- age×gender and
 * city, each a CURRENT snapshot, not a time series. There is no per-day
 * demographic history need (the report shows "who follows this account
 * right now", not a trend), so this is one row per account per dimension,
 * overwritten on every sync -- see InstagramInsights::syncAudience().
 *
 * Does not fit social_insights: that table is one metric -> one integer
 * value per row, and Meta's follower_demographics answers with a whole
 * array of {dimension_values, value} pairs per call (confirmed empirically
 * against both live connected accounts before this table was designed --
 * see docs/MONTHLY_REPORT.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_audience_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('dimension'); // 'age_gender' | 'city'
            $table->json('data'); // the raw `results` array from Meta
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['social_account_id', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_audience_snapshots');
    }
};
