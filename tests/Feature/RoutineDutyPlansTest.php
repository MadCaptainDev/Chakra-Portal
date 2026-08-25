<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
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

/**
 * The four real studio duty shapes: daily IG DMs/comments fan-out, daily
 * hard-disk handoff, every-2-days clean, every-10-days training.
 */
class RoutineDutyPlansTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_four_duty_templates_can_exist_without_subjects_or_users(): void
    {
        User::factory()->create();
        $this->seed(\Database\Seeders\RoutineDutyPlansSeeder::class);

        foreach ([
            'Venture Direct Messages and Comments',
            'Move final output to hard disk',
            'Verify training',
            'Clean the office',
        ] as $title) {
            $this->assertDatabaseHas('routines', ['title' => $title, 'is_active' => 1]);
        }

        $dm = Routine::query()->where('title', 'Venture Direct Messages and Comments')->firstOrFail();
        $this->assertSame(0, $dm->subjects()->count());
        $this->assertSame(0, $dm->users()->count());
        $this->assertSame(Routine::SUBJECT_ACCOUNTS, $dm->subject_type);
        $this->assertEqualsCanonicalizing(['Messages', 'Comments'], $dm->checkpoints()->pluck('name')->all());
        $this->assertSame('0', $dm->fields()->where('key', 'replied_to_messages')->value('default_value'));

        $disk = Routine::query()->where('title', 'Move final output to hard disk')->firstOrFail();
        $this->assertNull($disk->subject_type);
        $this->assertSame('disk_name', $disk->fields()->value('key'));

        $training = Routine::query()->where('title', 'Verify training')->firstOrFail();
        $this->assertSame(10, $training->schedule_interval);

        $clean = Routine::query()->where('title', 'Clean the office')->firstOrFail();
        $this->assertSame(2, $clean->schedule_interval);
        $this->assertSame(0, $clean->fields()->count());

        // Account-scoped with no toggles must not invent a null-subject row.
        Carbon::setTestNow('2026-08-25 12:00:00');
        $dm->update(['starts_on' => '2026-08-25', 'catch_up_days' => 0, 'is_active' => true]);
        Routine::query()->where('id', '!=', $dm->id)->update(['is_active' => false]);
        $this->assertSame(0, app(RoutineOccurrenceGenerator::class)->run());
    }

    public function test_daily_dm_comments_fans_out_per_checkpoint_and_account(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = $this->dmCommentsRoutine();
        $a = $this->socialAccount('brand_a');
        $b = $this->socialAccount('brand_b');
        $content = ContentAccount::factory()->create(['name' => 'Venture Desk']);

        $routine->subjects()->create(['subject_type' => Routine::SUBJECT_SOCIAL, 'subject_id' => $a->id]);
        $routine->subjects()->create(['subject_type' => Routine::SUBJECT_SOCIAL, 'subject_id' => $b->id]);
        $routine->subjects()->create(['subject_type' => Routine::SUBJECT_CONTENT, 'subject_id' => $content->id]);
        $routine->users()->attach(User::factory()->employee()->create());

        $created = app(RoutineOccurrenceGenerator::class)->run();

        // 2 checkpoints × 3 accounts = 6
        $this->assertSame(6, $created);
        $this->assertSame(6, RoutineOccurrence::count());
        $this->assertSame(2, RoutineOccurrence::where('subject_id', $a->id)->where('subject_type', Routine::SUBJECT_SOCIAL)->count());
    }

    public function test_daily_hard_disk_completes_with_optional_disk_name_first_doer_wins(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'title' => 'Move final output to hard disk',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'completion_mode' => Routine::MODE_SHARED,
        ]);
        $routine->fields()->create([
            'label' => 'Hard disk name',
            'key' => 'disk_name',
            'type' => RoutineField::TYPE_TEXT,
            'default_value' => '',
            'sort_order' => 0,
        ]);

        $a = User::factory()->employee()->create();
        $b = User::factory()->employee()->create();
        $routine->users()->attach([$a->id, $b->id]);

        app(RoutineOccurrenceGenerator::class)->run();
        $occurrence = RoutineOccurrence::firstOrFail();

        $completer = app(RoutineCompleter::class);
        $first = $completer->complete($occurrence, $a, ['disk_name' => 'Disk-Red']);
        $second = $completer->complete($occurrence->fresh(), $b, ['disk_name' => 'Disk-Blue']);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame($a->id, $occurrence->fresh()->completed_by);
        $this->assertSame('Disk-Red', $occurrence->fresh()->values['disk_name']);
    }

    public function test_clean_office_every_two_days(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $routine = Routine::factory()->everyNDays(2)->create([
            'title' => 'Clean the office',
            'starts_on' => '2026-08-01',
            'catch_up_days' => 10,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        app(RoutineOccurrenceGenerator::class)->run();

        $dates = RoutineOccurrence::orderBy('due_on')->pluck('due_on')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        $this->assertSame([
            '2026-08-01',
            '2026-08-03',
            '2026-08-05',
            '2026-08-07',
        ], $dates);
    }

    public function test_verify_training_every_ten_days(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');

        $routine = Routine::factory()->everyNDays(10)->create([
            'title' => 'Verify training',
            'starts_on' => '2026-08-01',
            'catch_up_days' => 40,
        ]);
        $routine->fields()->create([
            'label' => 'Trainee',
            'key' => 'trainee',
            'type' => RoutineField::TYPE_TEXT,
            'default_value' => '',
            'sort_order' => 0,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        app(RoutineOccurrenceGenerator::class)->run();

        $dates = RoutineOccurrence::orderBy('due_on')->pluck('due_on')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        $this->assertSame([
            '2026-08-01',
            '2026-08-11',
            '2026-08-21',
            '2026-08-31',
        ], $dates);
    }

    public function test_reply_counts_default_to_zero_when_left_blank(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = $this->dmCommentsRoutine();
        $account = $this->socialAccount('brand');
        $routine->subjects()->create([
            'subject_type' => Routine::SUBJECT_SOCIAL,
            'subject_id' => $account->id,
        ]);
        $employee = User::factory()->employee()->create();
        $routine->users()->attach($employee);

        app(RoutineOccurrenceGenerator::class)->run();
        $messages = RoutineOccurrence::query()
            ->whereHas('checkpoint', fn ($q) => $q->where('name', 'Messages'))
            ->firstOrFail();

        $result = app(RoutineCompleter::class)->complete($messages, $employee, []);

        $this->assertTrue($result['ok']);
        $this->assertEquals(0, $messages->fresh()->values['replied_to_messages']);
    }

    public function test_calendar_and_missed_panel_for_overdue_duty(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $routine = Routine::factory()->create([
            'title' => 'Clean the office',
            'starts_on' => '2026-08-20',
            'catch_up_days' => 10,
        ]);
        $routine->users()->attach($admin);
        app(RoutineOccurrenceGenerator::class)->run();

        $this->actingAs($admin)
            ->get(route('routines.calendar', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('Clean the office');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Missed duties')
            ->assertSee('Clean the office');
    }

    private function dmCommentsRoutine(): Routine
    {
        $routine = Routine::factory()->create([
            'title' => 'Venture Direct Messages and Comments',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'completion_mode' => Routine::MODE_SHARED,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);

        $messages = $routine->checkpoints()->create(['name' => 'Messages', 'sort_order' => 0]);
        $comments = $routine->checkpoints()->create(['name' => 'Comments', 'sort_order' => 1]);

        $routine->fields()->create([
            'checkpoint_id' => $messages->id,
            'label' => 'Replied to Messages',
            'key' => 'replied_to_messages',
            'type' => RoutineField::TYPE_NUMBER,
            'default_value' => '0',
            'sort_order' => 0,
        ]);
        $routine->fields()->create([
            'checkpoint_id' => $comments->id,
            'label' => 'Replied to Comments',
            'key' => 'replied_to_comments',
            'type' => RoutineField::TYPE_NUMBER,
            'default_value' => '0',
            'sort_order' => 1,
        ]);

        return $routine;
    }

    private function socialAccount(string $username): SocialAccount
    {
        $client = Client::factory()->create();

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => 'ig-'.$username,
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
