<?php

namespace App\Models;

use App\Support\TimesheetVenture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Script extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_WRITING = 'writing';

    public const STATUS_INTERNAL_REVIEW = 'internal_review';

    public const STATUS_CHANGES_REQUIRED = 'changes_required';

    public const STATUS_READY = 'ready';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_WRITING => 'Writing',
        self::STATUS_INTERNAL_REVIEW => 'Internal Review',
        self::STATUS_CHANGES_REQUIRED => 'Changes Required',
        self::STATUS_READY => 'Ready',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    /** Statuses that mean the script is finished with, for the default filter. */
    public const CLOSED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_ARCHIVED];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITIES = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_HIGH => 'High',
    ];

    /** Master lists this model draws on, mirroring PortfolioItem::TERM_FIELDS. */
    public const TERM_FIELDS = [
        'platform_id' => TaxonomyTerm::TYPE_PLATFORM,
        'script_type_id' => TaxonomyTerm::TYPE_SCRIPT_TYPE,
        'language_id' => TaxonomyTerm::TYPE_LANGUAGE,
    ];

    /*
     * last_edited_by_id and last_edited_at are absent from $fillable. They are
     * stamped by the save path itself, never accepted from a form -- otherwise
     * a crafted request could claim someone else wrote the last edit.
     */
    protected $fillable = [
        'client_id',
        'content_item_id',
        'title',
        'status',
        'priority',
        'writer_id',
        'editor_id',
        'created_by_id',
        'campaign',
        'platform_id',
        'script_type_id',
        'language_id',
        'target_seconds',
        'due_on',
    ];

    protected $casts = [
        'due_on' => 'date',
        'target_seconds' => 'integer',
        'last_edited_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The Notion-synced video/reel/post this script was written for --
     * set only by the Google Keep bulk importer (GoogleKeepImport),
     * matched by title. A script created the ordinary way has none.
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'writer_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function lastEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by_id');
    }

    /*
     * Named platformTerm() rather than platform() for the reason PortfolioItem
     * documents: a same-named column would shadow the relation.
     */
    public function platformTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'platform_id');
    }

    public function scriptTypeTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'script_type_id');
    }

    public function languageTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'language_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ScriptSection::class)->orderBy('position')->orderBy('id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function scopeForWriter(Builder $query, User $writer): void
    {
        $query->where('writer_id', $writer->id);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst((string) $this->priority);
    }

    /**
     * The client this was written for, as the studio spells it. Falls back to
     * nothing rather than guessing -- an unassigned script is a real state.
     */
    public function clientLabel(): ?string
    {
        return $this->client ? TimesheetVenture::canonicalForClient($this->client) : null;
    }

    /** "30 sec" / "1 min 30 sec", or null when no target was set. */
    public function durationLabel(): ?string
    {
        $seconds = $this->target_seconds;

        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return trim(($minutes ? $minutes.' min ' : '').($rest ? $rest.' sec' : ''));
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null && ! $this->isClosed() && $this->due_on->isPast();
    }

    /**
     * Record who last touched the writing. Called from the section save path,
     * which is the only thing that knows an edit happened.
     */
    public function touchEditedBy(User $user): void
    {
        $this->forceFill([
            'last_edited_by_id' => $user->id,
            'last_edited_at' => now(),
        ])->save();
    }
}
