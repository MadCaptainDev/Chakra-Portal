<?php

namespace App\Support;

use App\Models\Client;

/**
 * What the studio asks a client before writing for them.
 *
 * Ported from the design system's OnboardingSchema.jsx, and declarative for the
 * reason that file gives: every step, the review list and the staff-side
 * summary all read from this one array, so a question can be added without
 * touching layout code.
 *
 * In code rather than a table for the same reason the module list lives in
 * App\Support\Permission -- it changes about twice a year, git versions it for
 * free, and questions in a table buy an editing screen nobody opens plus the
 * problem of what a reworded question does to stored answers.
 *
 * ADDING A QUESTION is one entry under a step's `questions`. Adding a GROUP is
 * one entry in STEPS. Neither needs a migration or a line of Blade: the wizard,
 * the validation, the progress count, the review screen and the staff view are
 * all loops over this array. Removing a question leaves its answers in the
 * table, invisible and recoverable, which is intended.
 *
 * Question shape:
 *   type      textarea | text | chips | checks | urls | contact
 *   multi     chips/checks only -- many answers rather than one
 *   other     chips only -- offers "Other" with a free-text box beside it
 *   showIf    [otherKey, value] -- only asked when that answer was given
 *   required  blocks the step's Continue, and Submit
 *   optional  labelled "Optional" rather than merely left unmarked
 *   rows      textarea height
 *   max       character cap (default DEFAULT_MAX)
 */
class BrandBrief
{
    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_TEXT = 'text';

    public const TYPE_CHIPS = 'chips';

    public const TYPE_CHECKS = 'checks';

    public const TYPE_URLS = 'urls';

    public const TYPE_CONTACT = 'contact';

    /** How long a written answer may be when the question does not say. */
    public const DEFAULT_MAX = 2000;

    /** How many links the inspiration list accepts. */
    public const MAX_URLS = 6;

    /** The three fields a contact question collects, in order. */
    public const CONTACT_FIELDS = [
        'name' => ['label' => 'Name', 'placeholder' => 'Full name'],
        'phone' => ['label' => 'Phone Number', 'placeholder' => '+91 …'],
        'email' => ['label' => 'Email', 'placeholder' => 'name@company.com'],
    ];

    /**
     * The steps, in the order the client walks them.
     *
     * @var array<int, array{id: string, label: string, title: string, blurb: string, questions: array<string, array<string, mixed>>}>
     */
    public const STEPS = [
        [
            'id' => 'business',
            'label' => 'Business',
            'title' => 'About your business',
            'blurb' => 'Start with the basics — this is the part our writers read first.',
            'questions' => [
                'about' => [
                    'type' => self::TYPE_TEXTAREA, 'rows' => 5, 'required' => true,
                    'label' => 'Briefly describe your business.',
                    'placeholder' => 'Tell us about your business, what you offer, and what makes your brand special.',
                ],
            ],
        ],
        [
            'id' => 'goals',
            'label' => 'Goals',
            'title' => 'Business goals',
            'blurb' => 'What should marketing actually move for you?',
            'questions' => [
                'mainGoal' => [
                    'type' => self::TYPE_CHIPS, 'multi' => true, 'required' => true, 'other' => true,
                    'label' => 'What is your main goal with digital marketing?',
                    'help' => 'Pick as many as apply.',
                    'options' => ['Increase Brand Awareness', 'Generate Leads', 'Increase Sales', 'Increase Store Visits', 'Grow Social Media', 'Build Brand Identity', 'Other'],
                ],
                'horizon' => [
                    'type' => self::TYPE_TEXTAREA, 'rows' => 4,
                    'label' => 'What do you want to achieve in the next 3–6 months?',
                    'placeholder' => 'e.g. 500 enquiries a month from Instagram, or a full product catalogue shot.',
                ],
                'hero' => [
                    'type' => self::TYPE_TEXTAREA, 'rows' => 3,
                    'label' => 'Which product or service do you want to promote the most?',
                    'placeholder' => 'The one thing you would put on a billboard.',
                ],
            ],
        ],
        [
            'id' => 'audience',
            'label' => 'Audience',
            'title' => 'Target audience',
            'blurb' => 'Who we are talking to, and where they are.',
            'questions' => [
                'idealCustomer' => [
                    'type' => self::TYPE_TEXTAREA, 'rows' => 4, 'required' => true,
                    'label' => 'Who is your ideal customer?',
                    'placeholder' => 'Age, work, what they care about, why they buy from you.',
                ],
                'locations' => [
                    'type' => self::TYPE_TEXT, 'max' => 255,
                    'label' => 'Which locations do you want to target?',
                    'placeholder' => 'Trichy, Manapparai, Chennai, Tamil Nadu…',
                ],
                'ageGroups' => [
                    'type' => self::TYPE_CHIPS, 'multi' => true,
                    'label' => 'What age group are your main customers?',
                    'options' => ['Below 18', '18–24', '25–34', '35–44', '45–54', '55+', 'All Age Groups'],
                ],
            ],
        ],
        [
            'id' => 'brand',
            'label' => 'Brand',
            'title' => 'Brand & content',
            'blurb' => 'How it should feel, and what we should make.',
            'questions' => [
                'perception' => [
                    'type' => self::TYPE_CHIPS, 'multi' => true, 'required' => true, 'other' => true,
                    'label' => 'How do you want your brand to be perceived?',
                    'options' => ['Premium', 'Friendly', 'Professional', 'Modern', 'Traditional', 'Youthful', 'Luxury', 'Affordable', 'Bold', 'Other'],
                ],
                'contentTypes' => [
                    'type' => self::TYPE_CHIPS, 'multi' => true, 'other' => true,
                    'label' => 'Which type of content do you prefer?',
                    'options' => ['Reels', 'Product Videos', 'Educational Content', 'Promotional Content', 'Customer Testimonials', 'Behind the Scenes', 'Static Posts', 'Carousels', 'Stories', 'Other'],
                ],
                'language' => [
                    'type' => self::TYPE_CHIPS, 'other' => true,
                    'label' => 'Which language do you prefer?',
                    'options' => ['Tamil', 'English', 'Tanglish', 'Hindi', 'Other'],
                ],
                'onCamera' => [
                    'type' => self::TYPE_CHIPS,
                    'label' => 'Are you comfortable appearing in videos?',
                    'options' => ['Yes', 'No', 'Sometimes'],
                ],
                'avoid' => [
                    'type' => self::TYPE_TEXTAREA, 'rows' => 3, 'optional' => true,
                    'label' => 'Any topics or content styles you don’t want us to create?',
                    'placeholder' => 'Optional — anything off limits for your brand.',
                ],
            ],
        ],
        [
            'id' => 'competitors',
            'label' => 'Competitors',
            'title' => 'Competitors & inspiration',
            'blurb' => 'Show us the work you wish was yours.',
            'questions' => [
                'competitors' => [
                    'type' => self::TYPE_TEXTAREA, 'rows' => 4,
                    'label' => 'Who are your main competitors?',
                    'placeholder' => 'Names, or Instagram handles if you have them.',
                ],
                'inspiration' => [
                    'type' => self::TYPE_URLS,
                    'label' => 'Share 2–3 Instagram pages or brands whose content you like.',
                    'help' => 'Paste full links — we check them before the first shoot.',
                    'placeholder' => 'https://instagram.com/…',
                ],
            ],
        ],
        [
            'id' => 'marketing',
            'label' => 'Marketing',
            'title' => 'Marketing history',
            'blurb' => 'What you have already tried saves us repeating it.',
            'questions' => [
                'doneBefore' => [
                    'type' => self::TYPE_CHIPS, 'required' => true,
                    'label' => 'Have you done digital marketing before?',
                    'options' => ['Yes', 'No'],
                ],
                'whatWorked' => [
                    'type' => self::TYPE_TEXTAREA, 'rows' => 4, 'showIf' => ['doneBefore', 'Yes'],
                    'label' => 'What worked well, and what didn’t?',
                    'placeholder' => 'Channels, agencies, campaigns — the good and the bad.',
                ],
                'paidAds' => [
                    'type' => self::TYPE_CHIPS,
                    'label' => 'Do you currently run paid advertisements?',
                    'options' => ['Yes', 'No', 'Not Sure'],
                ],
                'budget' => [
                    'type' => self::TYPE_CHIPS,
                    'label' => 'Approximate monthly marketing / advertising budget?',
                    'options' => ['Below ₹10,000', '₹10,000 – ₹25,000', '₹25,000 – ₹50,000', '₹50,000 – ₹1,00,000', '₹1,00,000+', 'Prefer not to disclose'],
                ],
            ],
        ],
        [
            'id' => 'approval',
            'label' => 'Content & Approval',
            'title' => 'Content & approval',
            'blurb' => 'Who we call, and who signs off.',
            'questions' => [
                'contact' => [
                    'type' => self::TYPE_CONTACT, 'required' => true,
                    'label' => 'Who will be the main point of contact?',
                ],
                'approver' => [
                    'type' => self::TYPE_CHIPS,
                    'label' => 'Who will approve the content?',
                    'options' => ['Same as above', 'Different Person'],
                ],
                'approverPerson' => [
                    'type' => self::TYPE_CONTACT, 'showIf' => ['approver', 'Different Person'],
                    'label' => 'Approver details',
                ],
                'shootFrequency' => [
                    'type' => self::TYPE_CHIPS,
                    'label' => 'How frequently can we arrange shoots?',
                    'options' => ['Weekly', 'Twice a Month', 'Once a Month', 'As Required', 'Not Required'],
                ],
                'assets' => [
                    'type' => self::TYPE_CHECKS, 'multi' => true,
                    'label' => 'Do you already have photos, videos, logo and brand assets?',
                    'options' => ['Logo', 'Brand Guidelines', 'Product Photos', 'Product Videos', 'Previous Creatives', 'Brand Fonts / Colours', 'None'],
                ],
            ],
        ],
    ];

    /**
     * What the script drawer shows, in the order a writer wants it. Named here
     * rather than in the Blade so the drawer and the catalogue stay in step.
     */
    public const WRITER_KEYS = ['about', 'perception', 'language', 'idealCustomer', 'contentTypes', 'avoid'];

    public static function stepCount(): int
    {
        return count(self::STEPS);
    }

    /**
     * Every question, flattened and keyed, each stamped with the step it came
     * from. The wizard walks STEPS; everything that only needs "what is
     * question X" walks this.
     *
     * @return array<string, array<string, mixed>>
     */
    /**
     * Per-request caches. Class properties rather than method statics so
     * flush() can actually clear them -- a method static cannot be reset, and
     * the admin screen has to see its own edit on the next line.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $flat = null;

    /** @var array<string, array<string, array<string, mixed>>>|null */
    private static ?array $custom = null;

    /**
     * Which client the catalogue is currently being assembled for.
     *
     * Most of the brief is the same for everybody; a few clients have their
     * own group of questions nobody else is asked. Rather than thread a client
     * through eight call sites -- the wizard, the validator, the progress
     * count, the export, the staff view -- the catalogue is told once who it
     * is building for and the caches are keyed to it.
     *
     * Null means the shared questions only, and that is the safe default: a
     * caller that forgets to say gets everybody's questions, never one
     * client's private ones.
     */
    private static ?int $clientId = null;

    public static function questions(): array
    {
        if (self::$flat !== null) {
            return self::$flat;
        }

        $flat = [];

        foreach (self::stepsForClient() as $index => $step) {
            foreach ($step['questions'] as $key => $question) {
                $flat[$key] = $question + ['step' => $index, 'section' => $step['id']];
            }
        }

        return self::$flat = $flat;
    }

    /**
     * One group's questions: the code-defined ones first, then whatever the
     * studio has added to that group from Setup → Brief Questions.
     *
     * Code first on purpose. The built-in questions are the ones the writers
     * read first and the script drawer depends on, and a client should not
     * have to scroll past four questions somebody added last week to reach
     * "briefly describe your business".
     *
     * @return array<string, array<string, mixed>>
     */
    public static function questionsFor(string $stepId): array
    {
        $questions = [];

        foreach (self::STEPS as $step) {
            if ($step['id'] === $stepId) {
                $questions = $step['questions'];
                break;
            }
        }

        return $questions + (self::customQuestions()[$stepId] ?? []);
    }

    /**
     * The studio's own questions, grouped by step and cached for the request.
     *
     * Wrapped in a try: this is read while rendering, and a missing table
     * during a deploy that has not migrated yet must degrade to "no custom
     * questions" rather than white-screening every brief in the product.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function customQuestions(): array
    {
        if (self::$custom !== null) {
            return self::$custom;
        }

        $grouped = [];

        try {
            $stored = \App\Models\BriefQuestion::query()
                ->live()
                ->forClient(self::$clientId)
                ->ordered()
                ->get();
        } catch (\Throwable) {
            return self::$custom = [];
        }

        $stepIds = array_column(self::STEPS, 'id');
        $fallback = end($stepIds) ?: 'business';

        self::$clientLabel = null;

        foreach ($stored as $question) {
            if ($question->isPrivate()) {
                /*
                 * This client's own group. It keeps its own step id -- it is a
                 * tab of its own, not a question smuggled into a shared group
                 * it was never written for.
                 */
                $grouped[$question->step_id][$question->key] = $question->toCatalogue();
                self::$clientLabel = $question->group_label ?: self::$clientLabel;

                continue;
            }

            // A shared question whose group no longer exists lands in the last
            // group rather than vanishing -- an invisible question is one the
            // studio keeps re-adding, wondering why it never appears.
            $stepId = in_array($question->step_id, $stepIds, true) ? $question->step_id : $fallback;

            $grouped[$stepId][$question->key] = $question->toCatalogue();
        }

        return self::$custom = $grouped;
    }

    /** Whether a key belongs to the code catalogue rather than the studio's. */
    public static function isCoreKey(string $key): bool
    {
        foreach (self::STEPS as $step) {
            if (array_key_exists($key, $step['questions'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forget the per-request caches.
     *
     * Called after the admin screen writes a question, so the same request can
     * render the list it just changed, and between tests, which create
     * questions and then read the catalogue back.
     */
    public static function flush(): void
    {
        self::$flat = null;
        self::$custom = null;
    }

    /**
     * Assemble the catalogue for this client until told otherwise.
     *
     * Switching client throws the caches away, because they hold that client's
     * questions -- a clients list rendering six briefs in one request must not
     * show the fifth one the fourth one's private group.
     */
    public static function forClient(Client|int|null $client): void
    {
        $id = $client instanceof Client ? $client->id : $client;

        if ($id === self::$clientId) {
            return;
        }

        self::$clientId = $id;
        self::flush();
    }

    /**
     * Run something with the catalogue built for one client, then put it back.
     *
     * The safe way to render one client inside a loop over many: whatever the
     * callback does, the previous context is restored even if it throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(Client|int|null $client, callable $callback): mixed
    {
        $previous = self::$clientId;
        self::forClient($client);

        try {
            return $callback();
        } finally {
            self::forClient($previous);
        }
    }

    /**
     * The extra group a client's own questions appear under, or null when they
     * have none. Appended after the seven shared steps.
     *
     * @return array{id: string, label: string, title: string, blurb: string}|null
     */
    public static function clientStep(): ?array
    {
        if (! self::$clientId) {
            return null;
        }

        $stepId = \App\Models\BriefQuestion::stepIdFor(self::$clientId);

        if (($self = self::customQuestions()[$stepId] ?? []) === []) {
            return null;
        }

        $label = self::$clientLabel ?: 'Your work';

        return [
            'id' => $stepId,
            'label' => $label,
            'title' => $label,
            'blurb' => 'A few questions just for you — these help us write in your voice.',
            'questions' => $self,
        ];
    }

    /** The label of the client-specific group, read alongside its questions. */
    private static ?string $clientLabel = null;

    /**
     * The steps a client actually walks: the seven shared ones, plus their own
     * group when they have one.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * The groups a client actually has, keyed by step id -- the shared seven
     * plus their own. The read-view counterpart of stepsForClient().
     *
     * @return array<string, array{label: string, title: string, blurb: string}>
     */
    public static function sectionsForClient(): array
    {
        $sections = [];

        foreach (self::stepsForClient() as $step) {
            $sections[$step['id']] = [
                'label' => $step['label'],
                'title' => $step['title'],
                'blurb' => $step['blurb'],
            ];
        }

        return $sections;
    }

    public static function stepsForClient(): array
    {
        /*
         * The override goes on the LEFT of + on purpose. `+` keeps the left
         * operand's keys, and every step in STEPS already has a `questions`
         * key -- putting it on the right silently discards the studio's own
         * questions and the merge does nothing at all.
         */
        $steps = array_map(
            fn (array $step) => ['questions' => self::questionsFor($step['id'])] + $step,
            self::STEPS
        );

        if ($own = self::clientStep()) {
            $steps[] = $own;
        }

        return $steps;
    }

    /** @return array<string, array{label: string, title: string, blurb: string}> */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::STEPS as $step) {
            $sections[$step['id']] = [
                'label' => $step['label'],
                'title' => $step['title'],
                'blurb' => $step['blurb'],
            ];
        }

        return $sections;
    }

    public static function question(string $key): ?array
    {
        return self::questions()[$key] ?? null;
    }

    public static function isKnownKey(string $key): bool
    {
        // The "_other" companion of a chips question is a real stored answer,
        // not a stray key: it holds what the client typed beside "Other".
        return isset(self::questions()[$key]) || self::otherParent($key) !== null;
    }

    /** The question an "_other" key belongs to, or null if it is not one. */
    public static function otherParent(string $key): ?string
    {
        if (! str_ends_with($key, '_other')) {
            return null;
        }

        $parent = substr($key, 0, -6);

        return (self::question($parent)['other'] ?? false) ? $parent : null;
    }

    /**
     * Whether a question stores many values rather than one.
     *
     * urls and contact count: a list of links and a name/phone/email group are
     * both arrays, and everything that is not a scalar goes in value_json.
     */
    public static function isMulti(string $key): bool
    {
        $question = self::question($key);

        if (! $question) {
            return false;
        }

        return ($question['multi'] ?? false)
            || in_array($question['type'], [self::TYPE_URLS, self::TYPE_CONTACT], true);
    }

    /** @return list<string> */
    public static function requiredKeys(): array
    {
        return array_keys(array_filter(
            self::questions(),
            fn (array $question) => $question['required'] ?? false
        ));
    }

    /** @return list<string> */
    public static function optionsFor(string $key): array
    {
        return self::question($key)['options'] ?? [];
    }

    /**
     * Whether a question is asked at all, given what has been answered so far.
     *
     * The one piece of logic that must agree exactly between the client's form,
     * the server's validation and the staff read view: a question hidden on the
     * form must not be demanded on submit, and must not show as blank in the
     * summary.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function isVisible(string $key, array $answers): bool
    {
        $question = self::question($key);

        if (! $question || ! isset($question['showIf'])) {
            return true;
        }

        [$dependsOn, $expected] = $question['showIf'];

        return ($answers[$dependsOn] ?? null) === $expected;
    }

    /**
     * Whether an answer counts as given.
     *
     * A contact needs a name and one way to reach them -- a row with only a
     * phone number in it is not a point of contact.
     */
    public static function isAnswered(string $key, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (self::question($key)['type'] === self::TYPE_CONTACT) {
            return is_array($value)
                && filled($value['name'] ?? null)
                && (filled($value['phone'] ?? null) || filled($value['email'] ?? null));
        }

        if (is_array($value)) {
            return array_filter($value, fn ($one) => $one !== null && $one !== '') !== [];
        }

        return trim((string) $value) !== '';
    }
}
