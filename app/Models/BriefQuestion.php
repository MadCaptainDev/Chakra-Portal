<?php

namespace App\Models;

use App\Support\BrandBrief;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A brand brief question the studio added itself.
 *
 * See the migration for why these live beside the code catalogue rather than
 * replacing it. This class's job is to hand BrandBrief the same array shape a
 * code-defined question has, so nothing downstream can tell them apart.
 */
class BriefQuestion extends Model
{
    /**
     * The types a person can define from a form.
     *
     * urls and contact are deliberately absent: they carry their own field
     * layout and validation, and offering them here would mean explaining
     * what a "contact group" is on a settings screen to somebody who only
     * wanted to ask about parking.
     */
    public const TYPES = [
        BrandBrief::TYPE_TEXTAREA => 'Long answer',
        BrandBrief::TYPE_TEXT => 'Short answer',
        BrandBrief::TYPE_CHIPS => 'Pick from a list',
        BrandBrief::TYPE_CHECKS => 'Tick a list',
    ];

    /** How far apart new questions sort, so one can be slotted between two. */
    private const SORT_STEP = 10;

    /*
     * `key` is absent: it is derived once on creation and never posted. A
     * settable key would let an edit re-point a question at another one's
     * answers.
     */
    protected $fillable = [
        'step_id',
        'type',
        'label',
        'help',
        'options',
        'multi',
        'required',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'multi' => 'boolean',
        'required' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Derived once, from the first label, and never again. Answers are
        // keyed by it, so a later rename must not move them.
        static::creating(function (self $question) {
            $question->key ??= self::freshKey($question->label);
            $question->sort_order = $question->sort_order ?: self::nextSortOrder($question->step_id);
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('step_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The question as BrandBrief hands it to the form.
     *
     * Deliberately the same shape as a constant entry -- the wizard, the
     * validator and the read view all branch on `type`, and none of them
     * should have to ask where a question came from.
     *
     * @return array<string, mixed>
     */
    public function toCatalogue(): array
    {
        $question = [
            'type' => $this->type,
            'label' => $this->label,
            'section' => $this->step_id,
            // Marks it as editable, which is the one thing the admin screen
            // needs to know and nothing else cares about.
            'custom' => true,
        ];

        if (filled($this->help)) {
            $question['help'] = $this->help;
        }

        if ($this->required) {
            $question['required'] = true;
        }

        if (in_array($this->type, [BrandBrief::TYPE_CHIPS, BrandBrief::TYPE_CHECKS], true)) {
            $question['options'] = array_values($this->options ?? []);
            $question['multi'] = $this->multi;
        } else {
            $question['rows'] = $this->type === BrandBrief::TYPE_TEXTAREA ? 4 : null;
        }

        return array_filter($question, fn ($value) => $value !== null);
    }

    /**
     * A key that is unique across BOTH the stored questions and the code
     * catalogue -- a custom question keyed `about` would silently share
     * answers with the built-in one.
     */
    public static function freshKey(string $label): string
    {
        $base = Str::of($label)->slug('_')->limit(40, '')->toString() ?: 'question';
        $key = $base;
        $suffix = 2;

        while (self::where('key', $key)->exists() || BrandBrief::isCoreKey($key)) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    private static function nextSortOrder(string $stepId): int
    {
        return ((int) self::where('step_id', $stepId)->max('sort_order')) + self::SORT_STEP;
    }
}
