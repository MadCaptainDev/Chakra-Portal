<?php

namespace App\Models;

use App\Support\BrandBrief;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    /** Meta-approved template for cold brand-brief reminders. */
    public const WHATSAPP_TEMPLATE = 'brand_brief_reminder';

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
        'token_issued_at' => 'datetime',
        'public_submitted_at' => 'datetime',
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

    /**
     * Issue (or replace) the link the studio sends out.
     *
     * Replacing rather than adding is the whole point: one live link per
     * brief, so sending it to the wrong address is fixed by reissuing rather
     * than by hoping. forceFill because none of this is anybody's to post.
     */
    public function issuePublicToken(?User $by = null): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'public_token' => $token,
            'token_issued_at' => now(),
            'token_issued_by_id' => $by?->id,
        ])->save();

        return $token;
    }

    public function revokePublicToken(): void
    {
        $this->forceFill([
            'public_token' => null,
            'token_issued_at' => null,
            'token_issued_by_id' => null,
        ])->save();
    }

    public function publicUrl(): ?string
    {
        return $this->public_token ? route('brief.public', $this->public_token) : null;
    }

    /**
     * Whether the public link still accepts answers.
     *
     * Submitted means closed -- filled once, as the studio asks. A signed-in
     * client can still revise through the portal, which is deliberate and is
     * not the same promise: the portal knows who is typing, a shared URL does
     * not.
     */
    public function acceptsPublicEdits(): bool
    {
        return $this->public_token !== null && ! $this->isSubmitted();
    }

    /**
     * Put a submitted brief back in the client's hands.
     *
     * The escape hatch for the one-time rule. Somebody will answer question
     * four wrong and press Submit, and without this the only remedy is a staff
     * member retyping their words for them.
     */
    public function reopen(): void
    {
        $this->forceFill([
            'status' => self::STATUS_IN_PROGRESS,
            'submitted_at' => null,
            'submitted_by_id' => null,
            'public_submitted_at' => null,
        ])->save();
    }

    /**
     * The whole brief as plain text, for pasting into a script doc, an email
     * or a WhatsApp message to whoever is writing.
     *
     * Plain text rather than PDF on purpose: this gets pasted into other
     * things far more often than it gets printed, and a PDF is the format you
     * cannot paste. Unanswered questions are skipped -- a page of "—" is
     * harder to read than a short page.
     */
    public function toText(): string
    {
        $lines = [
            'BRAND BRIEF — '.$this->client->name,
            str_repeat('=', 60),
            'Status: '.$this->statusLabel()
                .($this->submitted_at ? ', submitted '.$this->submitted_at->format('j M Y') : ''),
            'Exported: '.now()->format('j M Y, H:i'),
        ];

        foreach (BrandBrief::sections() as $sectionKey => $section) {
            $questions = BrandBrief::questionsFor($sectionKey);

            $answered = collect($questions)
                ->filter(fn ($q, $key) => $this->has($key));

            if ($answered->isEmpty()) {
                continue;
            }

            $lines[] = '';
            $lines[] = mb_strtoupper($section['label']);
            $lines[] = str_repeat('-', 60);

            foreach ($questions as $key => $question) {
                if (! $this->has($key)) {
                    continue;
                }

                $value = $this->displayAnswer($key);

                $lines[] = '';
                $lines[] = $question['label'];
                // Indented so a long answer stays visibly attached to its
                // question when this is pasted somewhere that does not wrap.
                $lines[] = '    '.str_replace("\n", "\n    ", $value);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * One answer as a human would read it: lists joined, a chosen "Other"
     * shown with what the client typed beside it, a contact group flattened.
     *
     * The single place that knows how each answer type reads, so the export,
     * the review screen and the staff summary cannot disagree about it.
     */
    public function displayAnswer(string $key): string
    {
        $value = $this->answer($key);
        $type = BrandBrief::question($key)['type'] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if ($type === BrandBrief::TYPE_CONTACT) {
            return collect(array_keys(BrandBrief::CONTACT_FIELDS))
                ->map(fn (string $field) => is_array($value) ? ($value[$field] ?? null) : null)
                ->filter()
                ->implode(' · ');
        }

        // "Other" on its own tells a reader nothing; it is the free text
        // beside it that carries the answer.
        $other = $this->answer($key.'_other');
        $label = fn ($one) => ($one === 'Other' && filled($other)) ? 'Other: '.$other : (string) $one;

        if (is_array($value)) {
            return collect($value)->filter()->map($label)->implode(', ');
        }

        return $label($value);
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
        return $this->requiredTotal() - count($this->missingRequired());
    }

    /**
     * How many required questions are actually being asked.
     *
     * Conditional questions count only when their condition holds, so the
     * denominator moves with the answers -- "3 of 5" must not include a
     * question the client will never be shown.
     */
    public function requiredTotal(): int
    {
        $answers = $this->answerMap();

        return collect(BrandBrief::requiredKeys())
            ->filter(fn (string $key) => BrandBrief::isVisible($key, $answers))
            ->count();
    }

    /** The required questions still outstanding, for the Submit refusal. */
    public function missingRequired(): array
    {
        $stored = $this->keyedAnswers();
        $answers = $this->answerMap();

        return array_values(array_filter(
            BrandBrief::requiredKeys(),
            fn (string $key) => BrandBrief::isVisible($key, $answers)
                && ! ($stored->get($key)?->isAnswered() ?? false)
        ));
    }

    /**
     * Every stored answer as key => value, which is the shape BrandBrief's
     * showIf checks read. Built once per call rather than per question.
     *
     * @return array<string, mixed>
     */
    public function answerMap(): array
    {
        return $this->keyedAnswers()
            ->map(fn (ClientBriefAnswer $answer) => $answer->answer())
            ->all();
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
        $stored = $this->keyedAnswers();
        $answers = $this->answerMap();

        // Hidden questions are excluded from both halves, so a step does not
        // read "2 of 4" when only three of its questions are ever shown.
        $asked = collect(array_keys(BrandBrief::questionsFor($section)))
            ->filter(fn (string $key) => BrandBrief::isVisible($key, $answers));

        return [
            'answered' => $asked
                ->filter(fn (string $key) => $stored->get($key)?->isAnswered() ?? false)
                ->count(),
            'total' => $asked->count(),
        ];
    }

    /** Whether every question this step asks has been answered. */
    public function sectionComplete(string $section): bool
    {
        $progress = $this->sectionProgress($section);

        return $progress['total'] > 0 && $progress['answered'] === $progress['total'];
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
