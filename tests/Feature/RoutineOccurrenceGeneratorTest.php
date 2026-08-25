<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Routine;
use App\Models\RoutineField;
use App\Models\RoutineOccurrence;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\RoutineCompleter;
use App\Services\RoutineOccurrenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoutineOccurrenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_generate_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $user = User::factory()->employee()->create();
        $routine->users()->attach($user);

        $generator = app(RoutineOccurrenceGenerator::class);
        $first = $generator->run();
        $second = $generator->run();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, RoutineOccurrence::count());
    }

    public function test_fan_out_checkpoints_times_accounts(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);
        $user = User::factory()->employee()->create();
        $routine->users()->attach($user);

        $routine->checkpoints()->create(['name' => 'DMs', 'sort_order' => 0]);
        $routine->checkpoints()->create(['name' => 'Comments', 'sort_order' => 1]);

        foreach (['one', 'two', 'three'] as $i => $name) {
            $account = $this->socialAccount($name, (string) (100 + $i));
            $routine->subjects()->create([
                'subject_type' => Routine::SUBJECT_SOCIAL,
                'subject_id' => $account->id,
            ]);
        }

        $created = app(RoutineOccurrenceGenerator::class)->run();

        $this->assertSame(6, $created);
        $this->assertSame(6, RoutineOccurrence::count());
    }

    public function test_new_account_does_not_retro_generate(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-18',
            'catch_up_days' => 31,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        $early = $this->socialAccount('early', '1');
        $routine->subjects()->create([
            'subject_type' => Routine::SUBJECT_SOCIAL,
            'subject_id' => $early->id,
        ]);

        app(RoutineOccurrenceGenerator::class)->run();
        $before = RoutineOccurrence::count();
        $this->assertGreaterThan(0, $before);

        Carbon::setTestNow('2026-08-25 12:00:00');

        $late = $this->socialAccount('late', '2');
        $routine->subjects()->create([
            'subject_type' => Routine::SUBJECT_SOCIAL,
            'subject_id' => $late->id,
        ]);

        app(RoutineOccurrenceGenerator::class)->run();

        $latePast = RoutineOccurrence::query()
            ->where('subject_id', $late->id)
            ->whereDate('due_on', '<', '2026-08-25')
            ->count();

        $this->assertSame(0, $latePast);

        $lateToday = RoutineOccurrence::query()
            ->where('subject_id', $late->id)
            ->whereDate('due_on', '2026-08-25')
            ->count();

        $this->assertSame(1, $lateToday);
    }

    public function test_individual_mode_creates_one_row_per_person(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->individual()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);

        $people = User::factory()->employee()->count(3)->create();
        $routine->users()->attach($people->pluck('id'));

        $created = app(RoutineOccurrenceGenerator::class)->run();

        $this->assertSame(3, $created);
        $this->assertSame(3, RoutineOccurrence::whereDate('due_on', '2026-08-25')->count());
        $this->assertEqualsCanonicalizing(
            $people->pluck('id')->all(),
            RoutineOccurrence::pluck('assigned_user_id')->all(),
        );
    }

    public function test_catch_up_days_caps_backfill(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-01-01',
            'catch_up_days' => 7,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        Carbon::setTestNow('2026-04-01 12:00:00'); // +90 days

        $created = app(RoutineOccurrenceGenerator::class)->run();

        // Inclusive window of 7 catch-up days from today = 8 dates (today + 7 back)
        $this->assertLessThanOrEqual(8, $created);
        $this->assertGreaterThanOrEqual(7, $created);
        $this->assertSame($created, RoutineOccurrence::count());

        $oldest = RoutineOccurrence::orderBy('due_on')->first();
        $this->assertTrue($oldest->due_on->gte(Carbon::parse('2026-03-25')));
    }

    public function test_first_doer_wins_shared_complete(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'completion_mode' => Routine::MODE_SHARED,
        ]);
        $a = User::factory()->employee()->create();
        $b = User::factory()->employee()->create();
        $routine->users()->attach([$a->id, $b->id]);

        app(RoutineOccurrenceGenerator::class)->run();
        $occurrence = RoutineOccurrence::first();

        $completer = app(RoutineCompleter::class);
        $first = $completer->complete($occurrence, $a);
        $second = $completer->complete($occurrence->fresh(), $b);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame($a->id, $second['winner']->id);
        $this->assertSame(RoutineOccurrence::STATUS_DONE, $occurrence->fresh()->status);
        $this->assertSame($a->id, $occurrence->fresh()->completed_by);
    }

    public function test_revoked_social_account_is_skipped(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        $live = $this->socialAccount('live', '10');
        $dead = $this->socialAccount('dead', '11');
        $dead->forceFill(['status' => SocialAccount::STATUS_REVOKED, 'access_token' => null])->save();

        $routine->subjects()->create(['subject_type' => Routine::SUBJECT_SOCIAL, 'subject_id' => $live->id]);
        $routine->subjects()->create(['subject_type' => Routine::SUBJECT_SOCIAL, 'subject_id' => $dead->id]);

        app(RoutineOccurrenceGenerator::class)->run();

        $this->assertSame(1, RoutineOccurrence::count());
        $this->assertSame($live->id, RoutineOccurrence::first()->subject_id);
    }

    private function socialAccount(string $username, string $platformUserId): SocialAccount
    {
        $client = Client::factory()->create();

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $platformUserId,
            'username' => $username,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill([
            'access_token' => 'IGQV-test',
            'connected_at' => now(),
        ])->save();

        return $account->fresh();
    }
}
