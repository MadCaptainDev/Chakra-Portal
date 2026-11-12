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

    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class)->orderByRaw('at_seconds is null')->orderBy('at_seconds')->orderBy('id');
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

        return $editor && ($user->id === $editor->id || $user->manages($editor));
    }

    /** May this person approve it or send it back? */
    public function reviewableBy(User $user): bool
    {
        $editor = $this->todo?->user;

        return $editor && $user->manages($editor) && ($user->id !== $editor->id || $user->isAdmin());
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
            'url' => route('reviews.show', $this),
        ];
    }
}
