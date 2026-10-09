<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Models\RoutineOccurrence;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\RoutineOccurrenceGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Setting up the Comment & Message Checker: which Instagram accounts, what
 * to check on each (DMs, Comments...), and who does it. Admin-only.
 *
 * The checker is the one account-scoped routine left now that the general
 * Routines module is gone; this screen is its only editor. Unlike the old
 * routine form it changes things in place -- a check that keeps its name
 * keeps its row, so the days already ticked stay attached to it, and only
 * the open (unticked) checks of a removed account or check are cleared.
 */
class InboxDeskSetupController extends Controller
{
    private const DEFAULT_CHECKS = ['DMs', 'Comments'];

    public function __construct(private readonly RoutineOccurrenceGenerator $generator) {}

    public function edit(): View
    {
        $checker = self::checker()?->load(['checkpoints', 'subjects', 'users']);

        return view('inbox-desk.setup', [
            'checker' => $checker,
            'checks' => $checker?->checkpoints->pluck('name')->all() ?: self::DEFAULT_CHECKS,
            'accountIds' => $checker?->subjects->where('subject_type', Routine::SUBJECT_SOCIAL)->pluck('subject_id')->map(fn ($id) => (int) $id)->all() ?? [],
            'userIds' => $checker?->users->pluck('id')->all() ?? [],
            'accounts' => SocialAccount::query()
                ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
                ->with('client:id,name')
                ->orderBy('username')
                ->get(),
            'staff' => User::staff()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'schedule_type' => ['required', Rule::in([Routine::SCHEDULE_DAILY, Routine::SCHEDULE_WEEKDAYS])],
            'catch_up_days' => ['required', 'integer', 'min:0', 'max:31'],
            'checks' => ['required', 'array', 'min:1', 'max:6'],
            'checks.*' => ['nullable', 'string', 'max:60'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', Rule::exists('social_accounts', 'id')->where('platform', SocialAccount::PLATFORM_INSTAGRAM)],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ], [
            'account_ids.required' => 'Pick at least one Instagram account to check.',
            'checks.required' => 'Add at least one thing to check, like DMs.',
        ]);

        $checks = collect($data['checks'])->map(fn ($n) => trim((string) $n))->filter()
            ->unique(fn ($n) => mb_strtolower($n))->values();
        if ($checks->isEmpty()) {
            return back()->withInput()->withErrors(['checks' => 'Add at least one thing to check, like DMs.']);
        }
        $accountIds = collect($data['account_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        DB::transaction(function () use ($request, $data, $checks, $accountIds) {
            $checker = self::checker() ?? new Routine([
                'subject_type' => Routine::SUBJECT_ACCOUNTS,
                'completion_mode' => Routine::MODE_SHARED,
                'starts_on' => today(),
                'created_by' => $request->user()->id,
            ]);
            $checker->fill([
                'title' => trim($data['title']),
                'schedule_type' => $data['schedule_type'],
                'catch_up_days' => (int) $data['catch_up_days'],
                'is_active' => $request->boolean('is_active'),
            ])->save();

            $this->syncChecks($checker, $checks->all());
            $this->syncAccounts($checker, $accountIds->all());
            $checker->users()->sync($data['user_ids'] ?? []);
        });

        $this->generator->run();

        return redirect()->route('inbox-desk.setup')->with('status', 'Checker saved.');
    }

    /** The checker: the account-scoped routine, oldest first if ever more than one. */
    public static function checker(): ?Routine
    {
        return Routine::query()->where('subject_type', Routine::SUBJECT_ACCOUNTS)->orderBy('id')->first();
    }

    /**
     * Keep checks matched by name, add new ones, drop the rest. Each check
     * has a "Replied to" number so the person can say how many they answered.
     *
     * @param  list<string>  $names
     */
    private function syncChecks(Routine $checker, array $names): void
    {
        $existing = $checker->checkpoints()->get()->keyBy(fn ($cp) => mb_strtolower($cp->name));
        $keep = [];

        foreach ($names as $i => $name) {
            $cp = $existing->get(mb_strtolower($name));
            if ($cp) {
                $cp->update(['name' => $name, 'sort_order' => $i]);
            } else {
                $cp = $checker->checkpoints()->create(['name' => $name, 'sort_order' => $i]);
            }
            $keep[] = $cp->id;

            if (! $checker->fields()->where('checkpoint_id', $cp->id)->exists()) {
                $checker->fields()->create([
                    'checkpoint_id' => $cp->id,
                    'label' => 'Replied to',
                    'key' => $this->uniqueFieldKey($checker, 'replied_'.Str::slug($name, '_')),
                    'type' => 'number',
                    'is_required' => false,
                    'sort_order' => $i,
                ]);
            }
        }

        $gone = $checker->checkpoints()->whereNotIn('id', $keep)->pluck('id');
        if ($gone->isNotEmpty()) {
            RoutineOccurrence::query()->where('routine_id', $checker->id)
                ->whereIn('checkpoint_id', $gone)->where('status', RoutineOccurrence::STATUS_OPEN)->delete();
            $checker->checkpoints()->whereIn('id', $gone)->delete();
        }
    }

    /** @param  list<int>  $ids */
    private function syncAccounts(Routine $checker, array $ids): void
    {
        $current = $checker->subjects()->where('subject_type', Routine::SUBJECT_SOCIAL)->pluck('subject_id')->map(fn ($id) => (int) $id);

        foreach (array_diff($ids, $current->all()) as $id) {
            $checker->subjects()->create(['subject_type' => Routine::SUBJECT_SOCIAL, 'subject_id' => $id]);
        }

        $removed = array_values(array_diff($current->all(), $ids));
        if ($removed !== []) {
            $checker->subjects()->where('subject_type', Routine::SUBJECT_SOCIAL)->whereIn('subject_id', $removed)->delete();
            RoutineOccurrence::query()->where('routine_id', $checker->id)
                ->where('subject_type', Routine::SUBJECT_SOCIAL)->whereIn('subject_id', $removed)
                ->where('status', RoutineOccurrence::STATUS_OPEN)->delete();
        }
    }

    private function uniqueFieldKey(Routine $checker, string $key): string
    {
        $key = Str::limit($key, 56, '');
        $candidate = $key;
        for ($n = 2; $checker->fields()->where('key', $candidate)->exists(); $n++) {
            $candidate = $key.'_'.$n;
        }

        return $candidate;
    }
}
