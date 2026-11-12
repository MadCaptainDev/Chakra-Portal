<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded version of a cut, on its editing card. See
 * App\Http\Controllers\VideoReviewController.
 */
class ReviewVideo extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const CHANGES = 'changes';

    public const STATUSES = [
        self::PENDING => 'Waiting for review',
        self::APPROVED => 'Approved',
        self::CHANGES => 'Changes asked',
    ];

    public const VERDICTS = [
        'good_to_go' => 'Good to go',
        'minor_fixes' => 'Minor fixes',
        'needs_fixes' => 'Needs fixes',
    ];

    /** Every column is written by the controller and services that own the flow. */
    protected $fillable = [];

    protected $casts = [
        'ai_report' => 'array',
        'duration' => 'float',
        'ai_attempts' => 'integer',
        'ai_progress' => 'integer',
        'ai_stage_at' => 'datetime',
        'size' => 'integer',
        'reviewed_at' => 'datetime',
        'ai_checked_at' => 'datetime',
        'deleted_file_at' => 'datetime',
    ];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** Top-level comments, in the order they sit in the video; replies hang off them. */
    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class)->whereNull('parent_id')->orderByRaw('at_seconds is null')->orderBy('at_seconds')->orderBy('id');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(ReviewComment::class);
    }

    /** Who has OK'd this version. */
    public function approvals(): HasMany
    {
        return $this->hasMany(ReviewApproval::class)->orderBy('created_at');
    }

    /**
     * The sign-off: who must approve, who has, who is still to.
     *
     * @return array{required: list<array<string, mixed>>, extra: list<array<string, mixed>>, done: int, needed: int, complete: bool}
     */
    public function signOff(): array
    {
        $approvals = $this->approvals()->with('user:id,name,avatar_path')->get()->keyBy('user_id');
        $required = ReviewSetting::current()->approvers();

        $row = fn (User $u, $a) => ['id' => $u->id, 'name' => $u->name, 'approved' => (bool) $a, 'at' => $a?->created_at?->format('j M, g:i A'), 'note' => $a?->note];

        return [
            'required' => $required->map(fn (User $u) => $row($u, $approvals->get($u->id)))->values()->all(),
            'extra' => $approvals->reject(fn ($a) => $required->contains('id', $a->user_id))->map(fn ($a) => $row($a->user, $a))->values()->all(),
            'done' => $required->filter(fn (User $u) => $approvals->has($u->id))->count(),
            'needed' => $required->count(),
            'complete' => $required->isEmpty() ? $approvals->isNotEmpty() : $required->every(fn (User $u) => $approvals->has($u->id)),
        ];
    }

    public function filePath(): ?string
    {
        return $this->path ? storage_path('app/private/'.$this->path) : null;
    }

    public function hasFile(): bool
    {
        return $this->path && $this->deleted_file_at === null && is_file($this->filePath());
    }

    /** May this person see it? The card's editor, and anyone who manages them. */
    public function visibleTo(User $user): bool
    {
        $editor = $this->todo?->user;

        return $editor && ($user->id === $editor->id || $user->manages($editor) || ReviewSetting::current()->isApprover($user));
    }

    /** May this person approve it or send it back? */
    public function reviewableBy(User $user): bool
    {
        $editor = $this->todo?->user;

        if (! $editor || $user->isClient()) {
            return false;
        }
        $may = $user->manages($editor) || ReviewSetting::current()->isApprover($user);

        return $may && ($user->id !== $editor->id || $user->isAdmin());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function verdictLabel(): ?string
    {
        return $this->ai_verdict ? (self::VERDICTS[$this->ai_verdict] ?? ucfirst(str_replace('_', ' ', $this->ai_verdict))) : null;
    }

    /** @return array<string, mixed> what an editing card shows */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'ai_status' => $this->ai_status,
            'ai_verdict' => $this->ai_verdict,
            'ai_verdict_label' => $this->verdictLabel(),
            'open_comments' => $this->comments()->whereNull('resolved_at')->count(),
            'approvals' => ($so = $this->signOff())['done'],
            'approvals_needed' => $so['needed'],
            'url' => route('reviews.show', $this),
        ];
    }
}
