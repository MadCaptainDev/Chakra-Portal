<?php

namespace App\Http\Controllers;

use App\Models\ContentAccount;
use App\Models\Routine;
use App\Models\RoutineField;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\RoutineOccurrenceGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin definitions for repeating duties — CRUD shaped like taxonomy/index.
 */
class RoutineController extends Controller
{
    /** A plain duty -- cleaning the office is about nothing. The default. */
    public const SCOPE_NONE = 'none';

    /** Fans out over the selected Client Instagram / Venture accounts. */
    public const SCOPE_ACCOUNTS = 'accounts';

    public const SCOPES = [
        self::SCOPE_NONE => 'Just a duty — nothing to pick',
        self::SCOPE_ACCOUNTS => 'One for each account I choose',
    ];

    public function __construct(private readonly RoutineOccurrenceGenerator $generator) {}

    public function index(): View
    {
        return view('routines.index', [
            'routines' => Routine::query()
                ->withCount(['checkpoints', 'subjects', 'users', 'occurrences'])
                ->with(['users', 'subjects'])
                ->orderByDesc('is_active')
                ->orderBy('title')
                ->get(),
            'staff' => User::staff()->orderBy('name')->get(),
            'socialAccounts' => SocialAccount::query()
                ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
                ->with('client:id,name')
                ->orderBy('username')
                ->get(),
            'contentAccounts' => ContentAccount::query()
                ->with('client:id,name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $routine = DB::transaction(function () use ($data, $request) {
            $routine = Routine::create([
                ...$data['routine'],
                'created_by' => $request->user()->id,
            ]);

            $this->syncRelated($routine, $data);

            return $routine;
        });

        $this->generator->run();

        return redirect()
            ->route('routines.index')
            ->with('status', 'Routine “'.$routine->title.'” created.');
    }

    public function update(Request $request, Routine $routine): RedirectResponse
    {
        $data = $this->validated($request, $routine);

        DB::transaction(function () use ($routine, $data) {
            $routine->update($data['routine']);
            $this->syncRelated($routine, $data);
        });

        $this->generator->run();

        return redirect()
            ->route('routines.index')
            ->with('status', 'Routine updated.');
    }

    public function destroy(Routine $routine): RedirectResponse
    {
        $title = $routine->title;
        $routine->delete();

        return redirect()
            ->route('routines.index')
            ->with('status', 'Routine “'.$title.'” deleted.');
    }

    /**
     * @return array{
     *     routine: array<string, mixed>,
     *     user_ids: list<int>,
     *     checkpoint_names: list<string>,
     *     social_account_ids: list<int>,
     *     content_account_ids: list<int>,
     *     fields: list<array<string, mixed>>
     * }
     */
    private function validated(Request $request, ?Routine $routine = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'schedule_type' => ['required', Rule::in(array_keys(Routine::SCHEDULES))],
            'schedule_interval' => ['nullable', 'integer', 'min:1', 'max:365'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'completion_mode' => ['required', Rule::in(array_keys(Routine::MODES))],
            'subject_scope' => ['required', Rule::in([self::SCOPE_NONE, self::SCOPE_ACCOUNTS])],
            'catch_up_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            'starts_on' => ['required', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
            'checkpoint_names' => ['nullable', 'array'],
            'checkpoint_names.*' => ['nullable', 'string', 'max:120'],
            'social_account_ids' => ['nullable', 'array'],
            'social_account_ids.*' => [
                'integer',
                Rule::exists('social_accounts', 'id')->where('platform', SocialAccount::PLATFORM_INSTAGRAM),
            ],
            'content_account_ids' => ['nullable', 'array'],
            'content_account_ids.*' => ['integer', Rule::exists('content_accounts', 'id')],
            'fields' => ['nullable', 'array'],
            'fields.*.label' => ['required_with:fields', 'string', 'max:120'],
            'fields.*.key' => ['required_with:fields', 'string', 'max:64', 'alpha_dash'],
            'fields.*.type' => ['required_with:fields', Rule::in(array_keys(RoutineField::TYPES))],
            'fields.*.default_value' => ['nullable', 'string', 'max:255'],
            'fields.*.is_required' => ['sometimes', 'boolean'],
            'fields.*.checkpoint_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($data['schedule_type'] === Routine::SCHEDULE_EVERY_N_DAYS && empty($data['schedule_interval'])) {
            $data['schedule_interval'] = 1;
        }

        if ($data['schedule_type'] === Routine::SCHEDULE_MONTHLY && empty($data['day_of_month'])) {
            $data['day_of_month'] = (int) date('j', strtotime($data['starts_on']));
        }

        $accountScoped = $data['subject_scope'] === self::SCOPE_ACCOUNTS;

        // A plain duty keeps no accounts, even if stale checkboxes were posted
        // -- the scope answer is what decides, not what happens to be ticked.
        $socialIds = $accountScoped
            ? array_values(array_unique(array_map('intval', $data['social_account_ids'] ?? [])))
            : [];
        $contentIds = $accountScoped
            ? array_values(array_unique(array_map('intval', $data['content_account_ids'] ?? [])))
            : [];

        // The silent-death case, refused at the door. An account-scoped routine
        // with nothing to fan out over generates nothing and says nothing --
        // that is how the seeded DM duty sat dead. Make it un-saveable.
        if ($accountScoped && $socialIds === [] && $contentIds === []) {
            throw ValidationException::withMessages([
                'social_account_ids' => 'Pick at least one account, or set this routine to "Just a duty".',
            ]);
        }

        return [
            'routine' => [
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'schedule_type' => $data['schedule_type'],
                'schedule_interval' => $data['schedule_interval'] ?? null,
                'day_of_month' => $data['day_of_month'] ?? null,
                'completion_mode' => $data['completion_mode'],
                'subject_type' => $accountScoped ? Routine::SUBJECT_ACCOUNTS : null,
                'catch_up_days' => (int) ($data['catch_up_days'] ?? 31),
                'starts_on' => $data['starts_on'],
                'is_active' => $request->boolean('is_active', true),
            ],
            'user_ids' => array_values(array_unique(array_map('intval', $data['user_ids'] ?? []))),
            'checkpoint_names' => collect($data['checkpoint_names'] ?? [])
                ->map(fn ($n) => trim((string) $n))
                ->filter()
                ->values()
                ->all(),
            'social_account_ids' => $socialIds,
            'content_account_ids' => $contentIds,
            'fields' => collect($data['fields'] ?? [])
                ->filter(fn ($f) => filled($f['label'] ?? null) && filled($f['key'] ?? null))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{
     *     user_ids: list<int>,
     *     checkpoint_names: list<string>,
     *     social_account_ids: list<int>,
     *     content_account_ids: list<int>,
     *     fields: list<array<string, mixed>>
     * }  $data
     */
    private function syncRelated(Routine $routine, array $data): void
    {
        $routine->users()->sync($data['user_ids']);

        $routine->checkpoints()->delete();
        $checkpointIdsByName = [];
        foreach ($data['checkpoint_names'] as $i => $name) {
            $cp = $routine->checkpoints()->create([
                'name' => $name,
                'sort_order' => $i,
            ]);
            $checkpointIdsByName[mb_strtolower($name)] = $cp->id;
        }

        $routine->subjects()->delete();
        foreach ($data['social_account_ids'] as $id) {
            $routine->subjects()->create([
                'subject_type' => Routine::SUBJECT_SOCIAL,
                'subject_id' => $id,
            ]);
        }
        foreach ($data['content_account_ids'] as $id) {
            $routine->subjects()->create([
                'subject_type' => Routine::SUBJECT_CONTENT,
                'subject_id' => $id,
            ]);
        }

        $routine->fields()->delete();
        foreach ($data['fields'] as $i => $field) {
            $cpName = isset($field['checkpoint_name']) ? mb_strtolower(trim((string) $field['checkpoint_name'])) : '';
            $routine->fields()->create([
                'checkpoint_id' => $cpName !== '' ? ($checkpointIdsByName[$cpName] ?? null) : null,
                'label' => trim($field['label']),
                'key' => trim($field['key']),
                'type' => $field['type'],
                'default_value' => $field['default_value'] ?? null,
                'is_required' => (bool) ($field['is_required'] ?? false),
                'sort_order' => $i,
            ]);
        }
    }
}
