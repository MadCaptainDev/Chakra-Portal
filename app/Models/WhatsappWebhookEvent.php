<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One thing Meta told us: a message somebody sent, or the delivery status of
 * one we sent.
 *
 * Rows are written by the webhook and never edited. The point of storing the
 * whole payload alongside the parsed columns is that Meta adds message types
 * faster than anyone updates a parser -- when a type arrives that this class
 * does not understand, the row still lands, still shows in the admin list, and
 * the full JSON is there to read.
 */
class WhatsappWebhookEvent extends Model
{
    public const TYPE_MESSAGE = 'message';
    public const TYPE_STATUS = 'status';
    public const TYPE_ERROR = 'error';
    public const TYPE_OTHER = 'other';

    /**
     * A message the studio sent. Not a webhook event at all -- it is written by
     * the sender rather than by Meta -- but it lives here so that a message and
     * the sent/delivered/read events that follow it share one table and one
     * wamid, which is the only way the log reads as a conversation.
     */
    public const TYPE_OUTGOING = 'outgoing';

    /** How much of a message body the list preview keeps. */
    private const SUMMARY_LENGTH = 500;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    /**
     * File a message we sent, so the statuses Meta returns for it have
     * something to sit next to.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function recordOutgoing(
        string $to,
        ?string $wamid,
        string $messageType,
        string $summary,
        array $payload,
    ): self {
        return self::create([
            'object' => 'whatsapp_business_account',
            'field' => 'messages',
            'type' => self::TYPE_OUTGOING,
            // A send is unique by construction -- Meta issues one wamid per
            // accepted call -- so there is nothing to deduplicate against, and
            // the id alone is a sufficient key.
            'dedupe_key' => hash('sha256', self::TYPE_OUTGOING.'|'.($wamid ?? uniqid('', true))),
            'external_id' => $wamid,
            'wa_id' => $to,
            'message_type' => $messageType,
            'summary' => self::trim($summary),
            'payload' => $payload,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('received_at')->orderByDesc('id');
    }

    /**
     * Flatten one webhook body into rows.
     *
     * A single POST can carry several entries, each with several changes, each
     * with several messages *and* statuses -- Meta batches. Everything is
     * walked defensively with Arr::get: this input comes off the internet, and
     * a missing key must not cost us the rest of the batch.
     *
     * @param  array<string, mixed>  $payload
     * @return int  how many rows were new -- redeliveries return 0
     */
    public static function ingest(array $payload): int
    {
        $object = Arr::get($payload, 'object');
        $stored = 0;

        foreach (Arr::get($payload, 'entry', []) as $entry) {
            foreach (Arr::get($entry, 'changes', []) as $change) {
                $field = Arr::get($change, 'field');
                $value = Arr::get($change, 'value', []);

                $contacts = self::contactsByWaId($value);

                foreach (Arr::get($value, 'messages', []) as $message) {
                    $stored += self::store([
                        'object' => $object,
                        'field' => $field,
                        'type' => self::TYPE_MESSAGE,
                        'external_id' => Arr::get($message, 'id'),
                        'wa_id' => Arr::get($message, 'from'),
                        'contact_name' => $contacts[Arr::get($message, 'from')] ?? null,
                        'message_type' => Arr::get($message, 'type'),
                        'summary' => self::describeMessage($message),
                        'occurred_at' => self::timestamp(Arr::get($message, 'timestamp')),
                        'payload' => $message,
                    ]);
                }

                foreach (Arr::get($value, 'statuses', []) as $status) {
                    $stored += self::store([
                        'object' => $object,
                        'field' => $field,
                        'type' => self::TYPE_STATUS,
                        'external_id' => Arr::get($status, 'id'),
                        'wa_id' => Arr::get($status, 'recipient_id'),
                        'contact_name' => $contacts[Arr::get($status, 'recipient_id')] ?? null,
                        'status' => Arr::get($status, 'status'),
                        'summary' => self::describeErrors(Arr::get($status, 'errors', [])),
                        'occurred_at' => self::timestamp(Arr::get($status, 'timestamp')),
                        'payload' => $status,
                    ]);
                }

                /*
                 * Errors reported against the change itself rather than against
                 * one message -- an expired token, a number that lost its
                 * quality rating. These are the ones that explain why nothing
                 * is being delivered, so they are kept as their own type rather
                 * than folded in with everything else.
                 */
                foreach (Arr::get($value, 'errors', []) as $error) {
                    $stored += self::store([
                        'object' => $object,
                        'field' => $field,
                        'type' => self::TYPE_ERROR,
                        'summary' => self::describeErrors([$error]),
                        'payload' => $error,
                    ]);
                }

                // A change we do not recognise -- an account update, a template
                // status, a field subscribed by someone exploring the dashboard.
                // Recorded whole rather than dropped: an unexplained gap in this
                // log is worse than a row nobody reads.
                if (! Arr::has($value, ['messages']) && ! Arr::has($value, ['statuses']) && ! Arr::has($value, ['errors'])) {
                    $stored += self::store([
                        'object' => $object,
                        'field' => $field,
                        'type' => self::TYPE_OTHER,
                        'payload' => $change,
                    ]);
                }
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return int  1 if the row was new, 0 if Meta had already sent it
     */
    private static function store(array $attributes): int
    {
        $attributes['summary'] = self::trim($attributes['summary'] ?? null);
        $attributes['received_at'] = now();

        $key = self::dedupeKey($attributes);

        try {
            /*
             * firstOrCreate rather than create, because Meta redelivers
             * anything it does not get a fast 200 for, and the same wamid
             * arriving twice must not become two rows on the screen.
             */
            $event = self::firstOrCreate(['dedupe_key' => $key], $attributes);

            return $event->wasRecentlyCreated ? 1 : 0;
        } catch (QueryException $e) {
            /*
             * Two deliveries of the same event racing each other: one wins the
             * unique index and the other lands here. That is the guard doing
             * its job, not a failure -- swallowed so the rest of the batch is
             * still stored and Meta still gets its 200.
             */
            if (self::isDuplicate($e)) {
                return 0;
            }

            throw $e;
        }
    }

    /**
     * A stable fingerprint for one event.
     *
     * Includes the status, because sent/delivered/read legitimately share a
     * message id and are three separate events. Falls back to the payload
     * itself when there is no id at all, so an unknown change type is still
     * deduplicated by its content rather than being written on every retry.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function dedupeKey(array $attributes): string
    {
        $identity = $attributes['external_id']
            ?? json_encode($attributes['payload'] ?? [], JSON_UNESCAPED_UNICODE);

        return hash('sha256', implode('|', [
            $attributes['type'] ?? '',
            $attributes['status'] ?? '',
            $attributes['occurred_at']?->timestamp ?? '',
            $identity,
        ]));
    }

    /**
     * The name WhatsApp supplied for each number in this change, keyed by wa_id.
     *
     * Contacts arrive alongside messages rather than inside them, so this is
     * built once per change and looked up per row.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, string>
     */
    private static function contactsByWaId(array $value): array
    {
        $names = [];

        foreach (Arr::get($value, 'contacts', []) as $contact) {
            $waId = Arr::get($contact, 'wa_id');
            $name = Arr::get($contact, 'profile.name');

            if ($waId && $name) {
                $names[$waId] = $name;
            }
        }

        return $names;
    }

    /**
     * A one-line preview of an incoming message.
     *
     * The list has to be readable at a glance, and "text" in a type column is
     * not readable -- the words are. Media falls back to its caption, then to
     * the type in brackets, because an image with no caption still has to say
     * something.
     *
     * @param  array<string, mixed>  $message
     */
    private static function describeMessage(array $message): ?string
    {
        $type = Arr::get($message, 'type', 'unknown');

        return match ($type) {
            'text' => Arr::get($message, 'text.body'),
            'button' => Arr::get($message, 'button.text'),
            'interactive' => Arr::get($message, 'interactive.button_reply.title')
                ?? Arr::get($message, 'interactive.list_reply.title'),
            'reaction' => Arr::get($message, 'reaction.emoji'),
            'location' => trim(sprintf(
                '%s %s',
                Arr::get($message, 'location.name', ''),
                Arr::get($message, 'location.address', '')
            )) ?: '[location]',
            'image', 'video', 'audio', 'document', 'sticker' => Arr::get($message, $type.'.caption')
                ?? '['.$type.']',
            default => '['.$type.']',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private static function describeErrors(array $errors): ?string
    {
        if ($errors === []) {
            return null;
        }

        return collect($errors)
            ->map(fn (array $error) => trim(sprintf(
                '%s %s',
                Arr::get($error, 'code', ''),
                Arr::get($error, 'title') ?? Arr::get($error, 'message', '')
            )))
            ->filter()
            ->implode('; ') ?: null;
    }

    private static function timestamp(mixed $value): ?Carbon
    {
        // Meta sends unix seconds as a string. Anything else is not worth
        // guessing at -- a null occurred_at just means the list falls back to
        // when we received it.
        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : null;
    }

    private static function trim(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::SUMMARY_LENGTH);
    }

    private static function isDuplicate(QueryException $e): bool
    {
        // 23000 is the SQL standard integrity-constraint class, which is what
        // both MySQL and the SQLite used by the tests report a unique clash as.
        if (! str_starts_with((string) $e->getCode(), '23')) {
            return false;
        }

        Log::debug('WhatsApp webhook event already stored', ['error' => $e->getMessage()]);

        return true;
    }
}
