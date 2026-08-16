<?php

namespace App\Models;

use App\Support\BrandBrief;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One client's answers to the discovery questions, and how far through it is.
 *
 * The questions themselves are in App\Support\BrandBrief. This model holds the
 * state around them: whether it has been submitted, by whom, and -- through
 * answers() -- what was said.
 */
class ClientBrief extends Model
{
    use HasFactory;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUSES = [
        self::STATUS_NOT_STARTED => 'Not started',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_SUBMITTED => 'Submitted',
    ];

    /*
     * submitted_at and submitted_by_id are absent from $fillable. They are
     * stamped by submit() itself, never accepted from a form -- the same
     * reasoning as Script's last_edited_by_id.
     */
    protected $fillable = [
        'client_id',
        'status',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_NOT_STARTED,
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ClientBriefAnswer::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /**
     * The answers keyed by question key, ready for the form and the read view.
     *
     * Keyed rather than a list because every caller wants "what did they say
     * to X", and because the render loops the catalogue -- so a lookup miss
     * must be cheap and mean "unanswered", not "search the collection again".
     *
     * @return Collection<string, ClientBriefAnswer>
     */
    public function keyedAnswers(): Collection
    {
        return $this->answers->keyBy('question_key');
    }

    /** Whether a question has an answer worth showing. */
    public function has(string $key): bool
    {
        return $this->keyedAnswers()->get($key)?->isAnswered() ?? false;
    }

    /** The stored value for a question: a string, a list, or null. */
    public function answer(string $key): mixed
    {
        return $this->keyedAnswers()->get($key)?->answer();
    }

    /**
     * How many of the required questions are answered.
     *
     * Counted against the catalogue every time rather than stored, so adding a
     * question is still one entry in BrandBrief and not a migration plus a
     * backfill of every client's percentage.
     */
    public function requiredAnswered(): int
    {
        $answers = $this->keyedAnswers();

        return collect(BrandBrief::requiredKeys())
            ->filter(fn (string $key) => $answers->get($key)?->isAnswered() ?? false)
            ->count();
    }

    public function requiredTotal(): int
    {
        return count(BrandBrief::requiredKeys());
    }

    /** The required questions still outstanding, for the Submit refusal. */
    public function missingRequired(): array
    {
        $answers = $this->keyedAnswers();

        return array_values(array_filter(
            BrandBrief::requiredKeys(),
            fn (string $key) => ! ($answers->get($key)?->isAnswered() ?? false)
        ));
    }

    public function isComplete(): bool
    {
        return $this->missingRequired() === [];
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Not started';
    }

    /**
     * How many questions in a section have been answered, for the per-section
     * count that tells a client where the gap is without scrolling.
     *
     * @return array{answered: int, total: int}
     */
    public function sectionProgress(string $section): array
    {
        $questions = BrandBrief::questionsFor($section);
        $answers = $this->keyedAnswers();

        return [
            'answered' => collect(array_keys($questions))
                ->filter(fn (string $key) => $answers->get($key)?->isAnswered() ?? false)
                ->count(),
            'total' => count($questions),
        ];
    }

    /**
     * Whether this answer has been changed since the brief was first sent in.
     *
     * The thing a writer mid-script needs to see: not that the brief exists,
     * but that the tone words moved after they started.
     */
    public function editedSinceSubmit(string $key): bool
    {
        if (! $this->submitted_at) {
            return false;
        }

        $answer = $this->keyedAnswers()->get($key);

        return $answer && $answer->updated_at->gt($this->submitted_at);
    }
}
