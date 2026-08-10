<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A term in one of the app's controlled lists.
 *
 * Everything that used to be a free-text box with an implied set of answers
 * lives here under a `type`. Adding a new list is one entry in TYPES -- no
 * migration, no new table, no new screen.
 */
class TaxonomyTerm extends Model
{
    use HasFactory;

    public const TYPE_PLATFORM = 'platform';

    public const TYPE_FORMAT = 'format';

    public const TYPE_OBJECTIVE = 'objective';

    public const TYPE_SERVICE = 'service_type';

    public const TYPE_INDUSTRY = 'industry';

    public const TYPE_TAG = 'tag';

    /**
     * The lists, in the order the master-data screen shows them.
     *
     * `label` names one term, `plural` names the tab, and `hint` tells staff
     * what the list is for -- the screen is used by people who did not build
     * it, so each list explains itself.
     *
     * @var array<string, array{label: string, plural: string, hint: string}>
     */
    public const TYPES = [
        self::TYPE_PLATFORM => [
            'label' => 'Platform',
            'plural' => 'Platforms',
            'hint' => 'Where a piece was published — Instagram Reels, YouTube, a client’s own site.',
        ],
        self::TYPE_FORMAT => [
            'label' => 'Format',
            'plural' => 'Formats',
            'hint' => 'The shape of the film. A format containing “9:16” makes the case study show its cover vertically.',
        ],
        self::TYPE_OBJECTIVE => [
            'label' => 'Objective',
            'plural' => 'Objectives',
            'hint' => 'What the piece was made to do — awareness, launch, sales.',
        ],
        self::TYPE_SERVICE => [
            'label' => 'Service type',
            'plural' => 'Service types',
            'hint' => 'What kind of work it was, for our own reporting. Not shown on the website.',
        ],
        self::TYPE_INDUSTRY => [
            'label' => 'Industry',
            'plural' => 'Industries',
            'hint' => 'The client’s sector, set on the client record rather than on each piece.',
        ],
        self::TYPE_TAG => [
            'label' => 'Tag',
            'plural' => 'Tags',
            'hint' => 'Free keywords. A piece can carry several.',
        ],
    ];

    protected $fillable = [
        'type',
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Portfolio pieces pointing at this term through any of its single-value
     * roles, plus tagged ones. Used to warn before deleting.
     */
    public function usageCount(): int
    {
        return PortfolioItem::query()
            ->where(fn (Builder $query) => $query
                ->orWhere('platform_id', $this->id)
                ->orWhere('format_id', $this->id)
                ->orWhere('objective_id', $this->id)
                ->orWhere('service_type_id', $this->id)
                ->orWhereHas('tags', fn (Builder $tags) => $tags->whereKey($this->id)))
            ->count()
            + ($this->type === self::TYPE_INDUSTRY
                ? Client::where('industry_id', $this->id)->count()
                : 0);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? Str::headline($type);
    }

    public static function isKnownType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * Terms of one type, ready for a <select>, keyed by id.
     *
     * `$keep` is the term a record already uses: a retired term stays in its
     * own picker so editing a piece does not silently drop it.
     *
     * @return Collection<int, TaxonomyTerm>
     */
    public static function options(string $type, ?int $keep = null): Collection
    {
        return static::ofType($type)
            ->where(fn (Builder $query) => $query->active()->when($keep, fn (Builder $q) => $q->orWhere('id', $keep)))
            ->ordered()
            ->get();
    }

    /**
     * A slug unique within its own list. A platform and a tag may share one.
     */
    public static function uniqueSlug(string $type, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'term';
        $slug = $base;
        $suffix = 2;

        while (static::ofType($type)
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
