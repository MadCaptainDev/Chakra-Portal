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

    public const TYPE_SCRIPT_TYPE = 'script_type';

    public const TYPE_LANGUAGE = 'language';

    public const TYPE_EQUIPMENT_CATEGORY = 'equipment_category';

    public const TYPE_TASK_TYPE = 'task_type';

    public const TYPE_VENTURE = 'venture';

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
        self::TYPE_SCRIPT_TYPE => [
            'label' => 'Script type',
            'plural' => 'Script types',
            'hint' => 'The kind of script being written — ad film, explainer, testimonial.',
        ],
        self::TYPE_LANGUAGE => [
            'label' => 'Language',
            'plural' => 'Languages',
            'hint' => 'What a script is written in. Tamil and English are the usual two.',
        ],
        self::TYPE_EQUIPMENT_CATEGORY => [
            'label' => 'Equipment category',
            'plural' => 'Equipment categories',
            'hint' => 'How the kit register is grouped — camera, lens, lighting, audio, grip.',
        ],
        self::TYPE_VENTURE => [
            'label' => 'Venture',
            'plural' => 'Ventures',
            'hint' => 'Work that is not billable to one client on the list — internal projects, and anything typed into “Other” on a timesheet. Clients are ventures automatically and are not repeated here.',
        ],
        self::TYPE_TASK_TYPE => [
            'label' => 'Task type',
            'plural' => 'Task types',
            'hint' => 'What a timesheet entry was — shooting, editing, posting. The SLUG is what every entry stores, so renaming a term is safe but changing its slug would orphan the hours already logged against it.',
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
                : 0)
            // Scripts draw on the platform, script type and language lists.
            // Left out, the delete warning would report a term as unused while
            // scripts still point at it -- and the delete would null them all.
            + Script::query()
                ->where(fn (Builder $query) => $query
                    ->orWhere('platform_id', $this->id)
                    ->orWhere('script_type_id', $this->id)
                    ->orWhere('language_id', $this->id))
                ->count()
            // The kit register groups by category. Uncounted, deleting "Lens"
            // would report zero uses and null the category on every lens owned.
            + ($this->type === self::TYPE_EQUIPMENT_CATEGORY
                ? EquipmentItem::where('category_id', $this->id)->count()
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
