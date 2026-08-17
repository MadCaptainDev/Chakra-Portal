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

        // Delegated so the catalogue owns what "answered" means -- a contact
        // group needs a name and one way to reach them, which no generic
        // emptiness check can know.
        return BrandBrief::isAnswered($this->question_key, $value);
    }

}
