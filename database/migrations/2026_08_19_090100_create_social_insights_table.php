<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One metric, for one day or one range, for an account or one of its media.
 *
 * A row per metric rather than a JSON blob per sync, for the same reason
 * client_brief_answers is a row per question: the point of caching this
 * locally is being able to ask "reach on the 14th" with a WHERE, and a row
 * carries its own fetched_at, which is how a stale sync is told from a fresh
 * one without re-calling Instagram to find out.
 *
 * Two shapes share this table:
 *
 *   account-level   social_media_item_id NULL,  metric over a day or a range
 *   media-level     social_media_item_id set,   metric as of the sync, always
 *                    period_start = period_end (Instagram's media insights
 *                    are a current snapshot, not a date-ranged series)
 *
 * The metric SET is intentionally not fixed by an enum. Which metrics exist
 * changed under this integration mid-build (impressions retired for content
 * after 2 Jul 2024, in favour of views) and will change again -- the safe
 * assumption is that the list drifts, not that it is fixed. See
 * InstagramInsights for what is currently requested and how an unsupported
 * one is skipped rather than failing the sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_insights', function (Blueprint $table) {
            $table->id();

            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_media_item_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('metric', 60);
            // time_series | total_value -- kept because it decides how the
            // value was asked for, not because anything branches on it later.
            $table->string('metric_type', 20)->default('total_value');

            $table->unsignedBigInteger('value')->nullable();

            $table->string('period', 10)->default('day');
            $table->date('period_start');
            $table->date('period_end');

            /*
             * Upsert key. NOT a plain unique(social_account_id,
             * social_media_item_id, metric, period_start, period_end):
             * social_media_item_id is nullable, and both MySQL and SQLite
             * treat every NULL as distinct in a unique index, which would let
             * the same account-level metric/day insert twice without ever
             * tripping the constraint. A hash of the same fields, always
             * present, closes that gap -- the same fix WhatsappWebhookEvent
             * and SocialWebhookEvent already use for their own nullable
             * foreign keys.
             */
            $table->string('dedupe_key', 64)->unique();

            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['social_account_id', 'metric', 'period_start']);
            $table->index(['social_media_item_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_insights');
    }
};
