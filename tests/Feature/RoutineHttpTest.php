<?php

namespace Tests\Feature;

use App\Http\Controllers\RoutineController;
use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\Routine;
use App\Models\RoutineField;
use App\Models\RoutineOccurrence;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\RoutineOccurrenceGenerator;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoutineHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_employee_can_complete_permitted_occurrence(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $employee = User::factory()->employee()->create();
        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $routine->users()->attach($employee);
        $routine->fields()->create([
            'label' => 'DMs',
            'key' => 'dms',
            'type' => RoutineField::TYPE_NUMBER,
            'default_value' => '0',
            'sort_order' => 0,
        ]);

        app(RoutineOccurrenceGenerator::class)->run();
        $occurrence = RoutineOccurrence::first();

        $this->actingAs($employee)
            ->post(route('my.routines.complete', $occurrence), [
                'values' => ['dms' => 12],
            ])
            ->assertRedirect(route('my.routines'));

        $occurrence->refresh();
        $this->assertSame(RoutineOccurrence::STATUS_DONE, $occurrence->status);
        $this->assertSame($employee->id, $occurrence->completed_by);
        $this->assertEquals(12, $occurrence->values['dms']);
    }

    public function test_wrong_user_complete_returns_404(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $owner = User::factory()->employee()->create();
        $stranger = User::factory()->employee()->create();

        $routine = Routine::factory()->individual()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $routine->users()->attach([$owner->id, $stranger->id]);

        app(RoutineOccurrenceGenerator::class)->run();

        $ownersRow = RoutineOccurrence::where('assigned_user_id', $owner->id)->first();

        $this->actingAs($stranger)
            ->post(route('my.routines.complete', $ownersRow))
            ->assertNotFound();
    }

    public function test_admin_can_create_routine_definition(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('routines.store'), [
                'title' => 'Daily IG check',
                'schedule_type' => Routine::SCHEDULE_DAILY,
                'completion_mode' => Routine::MODE_SHARED,
                'subject_scope' => RoutineController::SCOPE_NONE,
                'starts_on' => today()->toDateString(),
                'catch_up_days' => 7,
                'is_active' => 1,
                'user_ids' => [$admin->id],
                'checkpoint_names' => ['DMs', 'Comments'],
            ])
            ->assertRedirect(route('routines.index'));

        $this->assertDatabaseHas('routines', ['title' => 'Daily IG check']);
        $this->assertSame(2, Routine::first()->checkpoints()->count());
    }

    /**
     * The day-nav on Routine Check travels under its own 'day' query key
     * (the route/controller pre-date x-day-nav, which everywhere else uses
     * 'date') -- day-nav's `param` prop is what lets this screen reuse the
     * shared component instead of hand-rolling its own prev/next markup.
     */
    public function test_routine_checks_day_nav_travels_under_its_own_query_key(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)
            ->get(route('routines.checking', ['day' => '2026-08-20']))
            ->assertOk();

        $response->assertSee('20 Aug 2026');
        // The "Next day" link must carry the SAME key forward, not 'date' --
        // a component bug here would silently reset every navigation back
        // to whatever the controller's own default day is.
        $response->assertSee(route('routines.checking', ['day' => '2026-08-21']), false);
    }

    public function test_definitions_screen_shows_active_inactive_and_account_scoped_counts(): void
    {
        $admin = User::factory()->create();

        Routine::factory()->create(['title' => 'Active plain', 'is_active' => true]);
        Routine::factory()->create(['title' => 'Retired', 'is_active' => false]);
        Routine::factory()->create(['title' => 'Watching accounts', 'is_active' => true, 'subject_type' => Routine::SUBJECT_ACCOUNTS]);

        $content = $this->actingAs($admin)->get(route('routines.index'))->assertOk()->getContent();

        // Not assertSeeInOrder: "1"/"2" are common enough elsewhere on the
        // page (per-routine meta lines, icon path data) that order alone
        // proves little. This pins each stat-card's own value to its own
        // label the way x-stat-card actually renders them -- the label,
        // then (past the icon span) the value in the next <p>.
        $statValue = function (string $label) use ($content): string {
            preg_match('/'.preg_quote($label, '/').'<\/p>.*?<p[^>]*>(\d+)</s', $content, $m);

            return $m[1] ?? 'NOT FOUND';
        };

        $this->assertSame('2', $statValue('Active'));
        $this->assertSame('1', $statValue('Inactive'));
        $this->assertSame('1', $statValue('Account-scoped'));
    }

    public function test_admin_can_toggle_social_and_content_account_subjects(): void
    {
        $admin = User::factory()->create();
        $social = $this->socialAccount('studio_brand');
        $content = ContentAccount::factory()->create(['name' => 'Venture Desk']);

        $this->actingAs($admin)
            ->post(route('routines.store'), [
                'title' => 'Scoped IG duty',
                'schedule_type' => Routine::SCHEDULE_DAILY,
                'completion_mode' => Routine::MODE_SHARED,
                'subject_scope' => RoutineController::SCOPE_ACCOUNTS,
                'starts_on' => today()->toDateString(),
                'catch_up_days' => 3,
                'is_active' => 1,
                'user_ids' => [$admin->id],
                'checkpoint_names' => ['Messages', 'Comments'],
                'social_account_ids' => [$social->id],
                'content_account_ids' => [$content->id],
                'fields' => [
                    [
                        'label' => 'Replied to Messages',
                        'key' => 'replied_to_messages',
                        'type' => RoutineField::TYPE_NUMBER,
                        'default_value' => '0',
                        'checkpoint_name' => 'Messages',
                    ],
                ],
            ])
            ->assertRedirect(route('routines.index'));

        $routine = Routine::query()->where('title', 'Scoped IG duty')->firstOrFail();
        $this->assertSame(Routine::SUBJECT_ACCOUNTS, $routine->subject_type);
        $this->assertSame(2, $routine->subjects()->count());
        $this->assertDatabaseHas('routine_subjects', [
            'routine_id' => $routine->id,
            'subject_type' => Routine::SUBJECT_SOCIAL,
            'subject_id' => $social->id,
        ]);
        $this->assertDatabaseHas('routine_subjects', [
            'routine_id' => $routine->id,
            'subject_type' => Routine::SUBJECT_CONTENT,
            'subject_id' => $content->id,
        ]);
    }

    public function test_instagram_accounts_master_route_is_gone(): void
    {
        $this->assertFalse(Route::has('instagram-accounts.index'));
    }

    public function test_admin_can_skip_with_reason(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $routine->users()->attach($admin);
        app(RoutineOccurrenceGenerator::class)->run();
        $occurrence = RoutineOccurrence::first();

        $this->actingAs($admin)
            ->post(route('routines.occurrences.skip', $occurrence), [
                'note' => 'Client paused for the week',
                'month' => '2026-08',
            ])
            ->assertRedirect();

        $this->assertSame(RoutineOccurrence::STATUS_SKIPPED, $occurrence->fresh()->status);
        $this->assertSame('Client paused for the week', $occurrence->fresh()->note);
    }

    /**
     * Six missed days are one late duty on the page, not six identical cards.
     */
    public function test_my_routines_collapses_a_backlog_into_one_duty(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $employee = User::factory()->employee()->create();
        $routine = Routine::factory()->create([
            'title' => 'Reply to DMs',
            'starts_on' => '2026-08-20',
            'catch_up_days' => 10,
        ]);
        $routine->users()->attach($employee);
        app(RoutineOccurrenceGenerator::class)->run();

        // 20th through the 25th inclusive.
        $this->assertSame(6, RoutineOccurrence::count());

        $response = $this->actingAs($employee)
            ->get(route('my.routines'))
            ->assertOk()
            ->assertSee('Reply to DMs')
            ->assertSee('5 days late')
            ->assertSee('6 outstanding');

        // One tickable row, not six.
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'name="duties[]"'),
        );
    }

    public function test_dashboard_shows_missed_duties_when_overdue(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $routine = Routine::factory()->create([
            'title' => 'Missed duty demo',
            'starts_on' => '2026-08-20',
            'catch_up_days' => 10,
        ]);
        $routine->users()->attach($admin);
        app(RoutineOccurrenceGenerator::class)->run();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Missed duties')
            ->assertSee('Missed duty demo');
    }

    public function test_routines_module_is_registered(): void
    {
        $this->assertArrayHasKey('routines', Permission::MODULES);
        $this->assertTrue(Permission::isKnownModule('routines'));
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
