<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;

/**
 * One thing a platform told us about a connected account.
 *
 * Instagram sends comments, mentions and messages here -- not insights, which
 * are pulled rather than pushed. Nothing acts on these yet; they are stored so
 * that when something does, the history is already there rather than starting
 * from the day the feature shipped.
 *
 * The whole payload is kept because the parsed columns are a guess about what
 * matters and the payload is the record of fact.
 */
class SocialWebhookEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Flatten one webhook body into rows.
     *
     * A single POST carries several entries, each with several changes -- Meta
     * batches. Walked defensively with Arr::get because this input comes off
     * the internet and a missing key must not cost the rest of the batch.
     *
     * @param  array<string, mixed>  $payload
     * @return int  how many rows were new; redeliveries return 0
     */
    public static function ingest(string $platform, array $payload): int
    {
        $object = Arr::get($payload, 'object');
        $stored = 0;

        foreach (Arr::get($payload, 'entry', []) as $entry) {
            $subjectId = Arr::get($entry, 'id');

            // Instagram uses `changes` for comments and mentions and
            // `messaging` for DMs. Both are walked; anything else is stored
            // whole rather than dropped, because an unexplained gap in this
            // log is worse than a row nobody reads.
            $items = Arr::get($entry, 'changes') ?: Arr::get($entry, 'messaging') ?: [$entry];

            foreach ($items as $item) {
                $stored += self::store([
                    'platform' => $platform,
                    'social_account_id' => self::accountFor($platform, $subjectId)?->id,
                    'object' => $object,
                    'field' => Arr::get($item, 'field'),
                    'external_id' => $subjectId,
                    'payload' => $item,
                ]);
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function store(array $attributes): int
    {
        $attributes['received_at'] = now();
        $key = hash('sha256', json_encode([
            $attributes['platform'],
            $attributes['external_id'],
            $attributes['field'],
            $attributes['payload'],
        ], JSON_UNESCAPED_UNICODE));

        try {
            // firstOrCreate, because Meta redelivers anything it does not get
            // a fast 200 for and the same event must not become two rows.
            return self::firstOrCreate(['dedupe_key' => $key], $attributes)->wasRecentlyCreated ? 1 : 0;
        } catch (QueryException $e) {
            // Two deliveries racing each other: one wins the unique index and
            // the other lands here. The guard doing its job, not a failure.
            return str_starts_with((string) $e->getCode(), '23') ? 0 : throw $e;
        }
    }

    private static function accountFor(string $platform, mixed $subjectId): ?SocialAccount
    {
        if (! $subjectId) {
            return null;
        }

        return SocialAccount::query()
            ->forPlatform($platform)
            ->where('platform_user_id', (string) $subjectId)
            ->first();
    }
}
