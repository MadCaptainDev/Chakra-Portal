<?php

namespace App\Support;

use App\Models\TaxonomyTerm;

/**
 * What the studio asks a client before writing for them.
 *
 * The question set lives here, in code, for the same reason the module list
 * lives in App\Support\Permission and the master lists live on TaxonomyTerm:
 * it changes about twice a year, git versions it for free, and the
 * alternative -- questions in a table -- buys an editing screen nobody opens
 * and the problem of what a reworded question does to thirty stored answers.
 *
 * Answers are rows (see the create_client_brief_tables migration), because
 * they do change constantly and have to be queryable.
 *
 * Adding a question is one entry in QUESTIONS. No migration, no Blade: the
 * form, the validation rules, the progress count and the staff-side view are
 * all loops over this array. Removing one leaves its answers in the table,
 * invisible and recoverable, which is the intended behaviour.
 *
 * Question shape:
 *   section   key from SECTIONS
 *   type      text | textarea | url | number | select | multiselect | choice
 *   required  whether Submit is refused without it. Keep this set small --
 *             see the note on the required count below.
 *   label     the question as the client reads it. A question, not a field
 *             name: "Who buys from you?" beats "Target audience".
 *   hint      optional, and the most valuable part of most questions. A client
 *             who does not know what a good answer looks like writes nothing.
 *   max       character limit for the text types (default 2000 for textarea).
 *   taxonomy  for select/multiselect: the TaxonomyTerm type it draws on.
 *   options   for choice/multiselect: a hardcoded key => label list.
 *   limit     for multiselect: how many may be picked.
 */
class BrandBrief
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_URL = 'url';

    public const TYPE_NUMBER = 'number';

    public const TYPE_SELECT = 'select';

    public const TYPE_MULTISELECT = 'multiselect';

    public const TYPE_CHOICE = 'choice';

    /** Types whose answer is a list, and so lives in value_json. */
    public const MULTI_TYPES = [self::TYPE_MULTISELECT];

    /** How long a long answer may be when the question does not say. */
    public const DEFAULT_MAX = 2000;

    /**
     * @var array<string, array{label: string, blurb: string}>
     */
    public const SECTIONS = [
        'brand' => [
            'label' => 'Your brand',
            'blurb' => 'The basics, so we are not guessing what you do.',
        ],
        'audience' => [
            'label' => 'Who you sell to',
            'blurb' => 'Every line we write is aimed at somebody. Tell us who.',
        ],
        'voice' => [
            'label' => 'How you sound',
            'blurb' => 'The difference between a script that sounds like you and one that sounds like everyone.',
        ],
        'goals' => [
            'label' => 'What this is for',
            'blurb' => 'What the videos have to actually do for the business.',
        ],
        'references' => [
            'label' => 'Who else is out there',
            'blurb' => 'Optional, and worth more than you would think.',
        ],
        'practical' => [
            'label' => 'How we work together',
            'blurb' => 'The logistics that decide how fast this moves.',
        ],
        'guardrails' => [
            'label' => "Do's and don'ts",
            'blurb' => 'The things we would otherwise only find out by getting them wrong.',
        ],
    ];

    /**
     * Eleven of these are required. That number is deliberate: a client can
     * finish the required set in about ten minutes, which is the difference
     * between a brief that comes back and one that sits open for a month. The
     * other twenty-one are what make a writer's day easier, and are prompted
     * for rather than demanded.
     *
     * @var array<string, array<string, mixed>>
     */
    public const QUESTIONS = [
        // -- Your brand ---------------------------------------------------
        'business_description' => [
            'section' => 'brand',
            'type' => self::TYPE_TEXTAREA,
            'required' => true,
            'label' => 'In a line or two, what does your business do?',
            'hint' => 'Say it the way you would to a stranger at a wedding. No jargon.',
            'max' => 600,
        ],
        'industry_id' => [
            'section' => 'brand',
            'type' => self::TYPE_SELECT,
            'required' => true,
            'label' => 'Which sector best describes you?',
            'taxonomy' => TaxonomyTerm::TYPE_INDUSTRY,
        ],
        'products_services' => [
            'section' => 'brand',
            'type' => self::TYPE_TEXTAREA,
            'required' => true,
            'label' => 'What do you sell? List your main products or services.',
        ],
        'hero_offering' => [
            'section' => 'brand',
            'type' => self::TYPE_TEXT,
            'required' => false,
            'label' => 'If we could only film one product or service, which would it be?',
            'hint' => 'The one that pays the bills, or the one you want to grow.',
        ],
        'usp' => [
            'section' => 'brand',
            'type' => self::TYPE_TEXTAREA,
            'required' => true,
            'label' => "What can you do that your competitors can't?",
            'hint' => 'The one thing a customer would miss if they went elsewhere.',
        ],
        'price_position' => [
            'section' => 'brand',
            'type' => self::TYPE_CHOICE,
            'required' => false,
            'label' => 'Where do you sit on price?',
            'options' => [
                'budget' => 'Budget — we compete on price',
                'mid' => 'Mid-market — fair price, good quality',
                'premium' => 'Premium — you pay for the quality',
                'luxury' => 'Luxury — price is not the point',
            ],
        ],
        'founder_story' => [
            'section' => 'brand',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Is there a founder or origin story worth telling on camera?',
        ],
        'service_area' => [
            'section' => 'brand',
            'type' => self::TYPE_TEXT,
            'required' => false,
            'label' => 'Which cities or areas do you serve?',
            'max' => 255,
        ],

        // -- Who you sell to ----------------------------------------------
        'audience_primary' => [
            'section' => 'audience',
            'type' => self::TYPE_TEXTAREA,
            'required' => true,
            'label' => 'Who buys from you? Describe your main customer.',
            'hint' => 'Age, where they live, what they do. A real person, not a demographic.',
        ],
        'audience_secondary' => [
            'section' => 'audience',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Anyone else you want to reach?',
        ],
        'audience_language_ids' => [
            'section' => 'audience',
            'type' => self::TYPE_MULTISELECT,
            'required' => true,
            'label' => 'What language should we speak to them in?',
            'hint' => 'Pick all that apply.',
            'taxonomy' => TaxonomyTerm::TYPE_LANGUAGE,
            'limit' => 6,
        ],
        'customer_objection' => [
            'section' => 'audience',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'What makes someone hesitate before buying?',
            'hint' => 'The doubt we can answer on camera.',
        ],
        'buying_trigger' => [
            'section' => 'audience',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'What usually makes someone finally buy?',
        ],

        // -- How you sound -------------------------------------------------
        /*
         * tone_traits is a hardcoded list, not a taxonomy, and the difference
         * matters. Master data is the studio's own operational lists, edited
         * by staff on /master-data. Tone vocabulary is part of the question:
         * if somebody renames "warm" to "friendly", every past answer silently
         * changes meaning. If this ever needs to be staff-editable, adding
         * TYPE_TONE to TaxonomyTerm::TYPES and swapping 'options' for
         * 'taxonomy' below is the whole change.
         */
        'tone_traits' => [
            'section' => 'voice',
            'type' => self::TYPE_MULTISELECT,
            'required' => true,
            'label' => 'Pick up to three words for how your brand should sound.',
            'hint' => 'Three is the limit on purpose. Everything cannot be the priority.',
            'limit' => 3,
            'options' => [
                'warm' => 'Warm',
                'playful' => 'Playful',
                'premium' => 'Premium',
                'straight' => 'Straight-talking',
                'expert' => 'Expert',
                'energetic' => 'Energetic',
                'calm' => 'Calm',
                'cheeky' => 'Cheeky',
                'traditional' => 'Traditional',
                'modern' => 'Modern',
                'aspirational' => 'Aspirational',
                'reassuring' => 'Reassuring',
            ],
        ],
        'tone_avoid' => [
            'section' => 'voice',
            'type' => self::TYPE_TEXT,
            'required' => false,
            'label' => 'Anything in that list you definitely do not want to sound like?',
            'max' => 255,
        ],
        'speaker' => [
            'section' => 'voice',
            'type' => self::TYPE_CHOICE,
            'required' => false,
            'label' => 'Should the videos speak as the brand, or as a person?',
            'options' => [
                'brand' => 'The brand — "we"',
                'founder' => 'The founder',
                'customer' => 'A customer',
                'narrator' => 'A narrator',
            ],
        ],
        'sample_copy' => [
            'section' => 'voice',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => "Paste a line or two of copy you're happy with.",
            'hint' => 'A caption, a tagline, even a WhatsApp broadcast you wrote yourself. Nothing beats an example.',
        ],
        'tagline' => [
            'section' => 'voice',
            'type' => self::TYPE_TEXT,
            'required' => false,
            'label' => 'Your tagline or slogan, if you have one.',
            'max' => 255,
        ],

        // -- What this is for ----------------------------------------------
        'objective_id' => [
            'section' => 'goals',
            'type' => self::TYPE_SELECT,
            'required' => true,
            'label' => 'What should this content do for you first?',
            'hint' => 'First. There is usually more than one, but only one can lead.',
            'taxonomy' => TaxonomyTerm::TYPE_OBJECTIVE,
        ],
        'success_metric' => [
            'section' => 'goals',
            'type' => self::TYPE_TEXTAREA,
            'required' => true,
            'label' => 'Six months from now, what would make you say this worked?',
            'hint' => 'Enquiries, walk-ins, followers, phone calls — whatever you actually count.',
        ],
        'platform_ids' => [
            'section' => 'goals',
            'type' => self::TYPE_MULTISELECT,
            'required' => true,
            'label' => 'Where will these go?',
            'taxonomy' => TaxonomyTerm::TYPE_PLATFORM,
            'limit' => 10,
        ],
        'default_cta' => [
            'section' => 'goals',
            'type' => self::TYPE_TEXT,
            'required' => false,
            'label' => 'What do you want a viewer to do at the end?',
            'hint' => 'Call, DM, visit the store, click the link.',
            'max' => 255,
        ],
        'upcoming' => [
            'section' => 'goals',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Anything coming up we should be planning for?',
            'hint' => 'Festivals, launches, a new branch, a sale.',
        ],

        // -- Who else is out there ------------------------------------------
        'competitors' => [
            'section' => 'references',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Name two or three competitors.',
        ],
        'competitor_view' => [
            'section' => 'references',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'What do they do well, and what would you do differently?',
        ],
        /*
         * A textarea and not a url field. Clients paste four links at once,
         * and a single-URL input turns that into four failed submissions.
         */
        'reference_links' => [
            'section' => 'references',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Links to videos you love — yours, theirs, or anyone at all.',
            'hint' => 'One link per line. They do not have to be from your industry.',
        ],
        'reference_why' => [
            'section' => 'references',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'What is it about those you like?',
            'hint' => 'The pace, the music, the writing, the look.',
        ],

        // -- How we work together --------------------------------------------
        'approver_name' => [
            'section' => 'practical',
            'type' => self::TYPE_TEXT,
            'required' => true,
            'label' => 'Who signs off on scripts?',
            'hint' => 'One name. Scripts that go to a committee take three times as long.',
            'max' => 255,
        ],
        'approval_turnaround' => [
            'section' => 'practical',
            'type' => self::TYPE_CHOICE,
            'required' => false,
            'label' => 'How long do you usually need to approve a script?',
            'hint' => 'An honest answer here saves a chase.',
            'options' => [
                'same_day' => 'Same day',
                'few_days' => '2–3 days',
                'week' => 'About a week',
                'longer' => 'Longer than a week',
            ],
        ],
        'on_camera' => [
            'section' => 'practical',
            'type' => self::TYPE_CHOICE,
            'required' => false,
            'label' => 'Is anyone willing to appear on camera?',
            'options' => [
                'founder' => 'The founder',
                'staff' => 'Staff',
                'models' => 'Hired models or actors',
                'none' => 'No faces — product only',
                'unsure' => 'Not sure yet',
            ],
        ],
        'assets_available' => [
            'section' => 'practical',
            'type' => self::TYPE_MULTISELECT,
            'required' => false,
            'label' => 'What do you already have that we can use?',
            'limit' => 7,
            'options' => [
                'logo' => 'Logo files',
                'brand_kit' => 'Brand colours and fonts',
                'photos' => 'Product photos',
                'videos' => 'Existing videos',
                'testimonials' => 'Customer testimonials',
                'premises' => 'Store or factory footage',
                'nothing' => 'Nothing yet',
            ],
        ],
        'website_url' => [
            'section' => 'practical',
            'type' => self::TYPE_URL,
            'required' => false,
            'label' => 'Your website',
        ],
        'instagram_url' => [
            'section' => 'practical',
            'type' => self::TYPE_URL,
            'required' => false,
            'label' => 'Your Instagram',
        ],

        // -- Do's and don'ts ---------------------------------------------------
        'must_include' => [
            'section' => 'guardrails',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Anything that must appear in every video?',
            'hint' => 'A legal line, a guarantee, an offer code, a phone number.',
        ],
        /*
         * High value, and deliberately not required. Forcing it produces "no"
         * from every client who has not thought about it, which is worse than
         * a blank we can ask about on a call. HIGH_VALUE_OPTIONAL below is how
         * the form asks for it without demanding it.
         */
        'never_say' => [
            'section' => 'guardrails',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Anything we must never say or show?',
            'hint' => 'Claims you cannot back up, a competitor by name, a discontinued product.',
        ],
        'sensitivities' => [
            'section' => 'guardrails',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Any cultural, religious or regional sensitivities we should know about?',
        ],
        'compliance' => [
            'section' => 'guardrails',
            'type' => self::TYPE_TEXTAREA,
            'required' => false,
            'label' => 'Any regulatory wording you are bound by?',
            'hint' => 'Common in healthcare, finance and food.',
        ],
    ];

    /**
     * Optional questions the form nudges for before Submit.
     *
     * These are the ones a writer misses most, and none of them can be made
     * required without turning them into noise. Naming them at the end -- "the
     * ones worth a minute if you have one" -- is the compromise.
     */
    public const HIGH_VALUE_OPTIONAL = [
        'never_say',
        'sample_copy',
        'reference_links',
        'customer_objection',
    ];

    /**
     * The condensed set a writer sees on the script editor.
     *
     * Not "everything, collapsed": a drawer that has to be read is a drawer
     * that gets closed. These are the answers that change the next sentence
     * somebody types.
     *
     * @var list<string>
     */
    public const WRITER_KEYS = [
        'tone_traits',
        'audience_primary',
        'objective_id',
        'default_cta',
        'must_include',
        'never_say',
        'sample_copy',
    ];

    /**
     * @return array<string, array{label: string, blurb: string}>
     */
    public static function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function questionsFor(string $section): array
    {
        return array_filter(self::QUESTIONS, fn (array $q) => $q['section'] === $section);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function question(string $key): ?array
    {
        return self::QUESTIONS[$key] ?? null;
    }

    /**
     * The gate. Anything not in the catalogue never reaches the database --
     * see ClientBriefRequest::prepareForValidation().
     */
    public static function isKnownKey(string $key): bool
    {
        return isset(self::QUESTIONS[$key]);
    }

    /**
     * @return list<string>
     */
    public static function requiredKeys(): array
    {
        return array_keys(array_filter(self::QUESTIONS, fn (array $q) => $q['required']));
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::QUESTIONS);
    }

    /** The TaxonomyTerm type a question draws on, if it draws on one. */
    public static function taxonomyFor(string $key): ?string
    {
        return self::QUESTIONS[$key]['taxonomy'] ?? null;
    }

    /**
     * The hardcoded key => label list for a choice or multiselect.
     *
     * @return array<string, string>
     */
    public static function optionsFor(string $key): array
    {
        return self::QUESTIONS[$key]['options'] ?? [];
    }

    /** Whether this question's answer is a list, and so lives in value_json. */
    public static function isMulti(string $key): bool
    {
        return in_array(self::QUESTIONS[$key]['type'] ?? '', self::MULTI_TYPES, true);
    }

    /**
     * Every taxonomy type the brief needs, so the controller can load the
     * option lists in one pass rather than a query per question.
     *
     * @return list<string>
     */
    public static function taxonomyTypes(): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (array $q) => $q['taxonomy'] ?? null, self::QUESTIONS)
        )));
    }
}
