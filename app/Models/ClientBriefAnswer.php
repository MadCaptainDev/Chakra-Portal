<?php

namespace App\Models;

use App\Support\BrandBrief;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one question.
 *
 * A row per question rather than a JSON blob on the brief, because the point
 * of collecting this in the portal is being able to ask "which clients said
 * Product launch" with a WHERE -- and because a row carries its own
 * updated_at, which is how the studio knows an answer moved after the script
 * was started.
 *
 * The two value columns are mutually exclusive: multi-selects go to
 * value_json, everything else to value. answer() is the only place that
 * branch lives.
 */
class ClientBriefAnswer extends Model
{
    protected $fillable = [
        'client_brief_id',
        'question_key',
        'value',
        'value_json',
    ];

    protected $casts = [
        'value_json' => 'array',
    ];

    public function brief(): BelongsTo
    {
        return $this->belongsTo(ClientBrief::class, 'client_brief_id');
    }

    /**
     * What the client said: a list for multi-selects, a string otherwise.
     */
    public function answer(): mixed
    {
        return BrandBrief::isMulti($this->question_key)
            ? ($this->value_json ?? [])
            : $this->value;
    }

    /**
     * Whether this counts as answered.
     *
     * An empty string and an empty array are both "not answered": a client who
     * types into a field and deletes it again has not answered, and progress
     * that ticks up for a cleared field is progress nobody trusts. "0" is
     * deliberately not empty -- it is a legitimate answer to a number.
     */
    public function isAnswered(): bool
    {
        $value = $this->answer();

        return is_array($value)
            ? $value !== []
            : ($value !== null && trim((string) $value) !== '');
    }

    /**
     * id => name for every term the brief can point at, loaded once.
     *
     * Without this, rendering a brief is a find() per term -- and a client who
     * picked ten platforms and six languages turns one read view into twenty
     * queries. Static because it is master data that does not change inside a
     * request; the property is reset between requests with the process.
     *
     * @var array<int, string>|null
     */
    private static ?array $termNames = null;

    /**
     * @return array<int, string>
     */
    private static function termNames(): array
    {
        return self::$termNames ??= TaxonomyTerm::query()
            ->whereIn('type', BrandBrief::taxonomyTypes())
            ->pluck('name', 'id')
            ->all();
    }

    /** Drops the memo. For tests, which create terms between assertions. */
    public static function forgetTermNames(): void
    {
        self::$termNames = null;
    }

    /**
     * The answer rendered for a human, resolving term ids and option keys back
     * to their labels.
     *
     * A retired taxonomy term still renders here: the lookup carries no active
     * scope, because an answer that vanishes from the screen when somebody
     * tidies master data is worse than a stale label.
     */
    public function display(): string
    {
        $key = $this->question_key;

        if (! BrandBrief::question($key) || ! $this->isAnswered()) {
            return '';
        }

        $taxonomy = BrandBrief::taxonomyFor($key);
        $options = BrandBrief::optionsFor($key);
        $value = $this->answer();

        $label = function ($one) use ($taxonomy, $options): string {
            if ($taxonomy) {
                return self::termNames()[(int) $one] ?? '';
            }

            return $options[$one] ?? (string) $one;
        };

        if (is_array($value)) {
            return collect($value)->map($label)->filter()->implode(', ');
        }

        return $taxonomy || $options ? $label($value) : (string) $value;
    }
}
