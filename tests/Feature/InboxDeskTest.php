<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Routine;
use App\Models\RoutineField;
use App\Models\RoutineOccurrence;
use App\Models\SocialAccount;
use App\Models\SocialWebhookEvent;
use App\Models\User;
use App\Models\WidgetToken;
use App\Services\RoutineOccurrenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Inbox Check: Instagram DMs and comments, account by account, ticked
 * one tile at a time and watched live from the widget.
 */
class InboxDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_permitted_employee_sees_every_account_with_a_tile_per_check(): void
    {
        [$routine, $sanjai] = $this->deskRoutine(['brand_a', 'brand_b']);

        $board = $this->actingAs($sanjai)->getJson(route('inbox-desk.state'))->assertOk()->json();

        $this->assertCount(1, $board['routines']);
        $this->assertSame(['DMs', 'Comments'], array_column($board['routines'][0]['checkpoints'], 'short'));
        $this->assertSame(['@brand_a', '@brand_b'], array_column($board['routines'][0]['accounts'], 'handle'));
        $this->assertSame(['done' => 0, 'total' => 4, 'left' => 4, 'late' => 0, 'accounts_left' => 2], $board['totals']);

        $this->actingAs($sanjai)->get(route('inbox-desk.index'))->assertOk()->assertSee('Inbox Check');
    }

    public function test_someone_not_on_the_routine_sees_nothing_and_cannot_tick(): void
    {
        [$routine] = $this->deskRoutine(['brand_a']);
        $stranger = User::factory()->employee()->create();
        $open = RoutineOccurrence::query()->open()->firstOrFail();

        $this->actingAs($stranger)->getJson(route('inbox-desk.state'))
            ->assertOk()->assertJsonPath('routines', []);

        $this->actingAs($stranger)->postJson(route('inbox-desk.check', $open->id))->assertNotFound();
        $this->assertSame(RoutineOccurrence::STATUS_OPEN, $open->fresh()->status);
    }

    public function test_a_client_login_is_refused(): void
    {
        $this->deskRoutine(['brand_a']);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($client)->get(route('inbox-desk.index'))->assertForbidden();
    }

    public function test_ticking_a_late_tile_closes_its_backlog_with_the_count_on_today_only(): void
    {
        Carbon::setTestNow(today()->subDays(2)->setTime(9, 0));
        [$routine, $sanjai] = $this->deskRoutine(['brand_a']);
        Carbon::setTestNow(today()->addDays(2)->setTime(9, 0));
        app(RoutineOccurrenceGenerator::class)->run();

        $dmCheckpoint = $routine->checkpoints->firstWhere('name', 'DMs');
        $board = $this->actingAs($sanjai)->getJson(route('inbox-desk.state'))->json();
        $tile = $board['routines'][0]['accounts'][0]['cells'][$dmCheckpoint->id];

        $this->assertSame('open', $tile['state']);
        $this->assertSame(2, $tile['late_days']);
        $this->assertSame(3, $tile['outstanding']);

        $after = $this->actingAs($sanjai)
            ->postJson(route('inbox-desk.check', $tile['id']), ['count' => 6])
            ->assertOk()
            ->json('board');

        $rows = RoutineOccurrence::query()->where('checkpoint_id', $dmCheckpoint->id)->orderBy('due_on')->get();
        $this->assertTrue($rows->every(fn ($r) => $r->status === RoutineOccurrence::STATUS_DONE));
        $this->assertSame([0, 0, 6], $rows->map(fn ($r) => (int) $r->values['replied_dms'])->all());

        $done = $after['routines'][0]['accounts'][0]['cells'][$dmCheckpoint->id];
        $this->assertSame('done', $done['state']);
        $this->assertSame(6, $done['count']);
        $this->assertTrue($done['can_undo']);
        $this->assertSame('DMs', $after['feed'][0]['checkpoint']);
        $this->assertCount(1, $after['feed'], 'a backlog closed by one tap is one line of activity');
    }

    public function test_undo_reopens_everything_one_tap_closed_and_only_for_its_owner_or_an_admin(): void
    {
        Carbon::setTestNow(today()->subDay()->setTime(9, 0));
        [$routine, $sanjai] = $this->deskRoutine(['brand_a']);
        Carbon::setTestNow(today()->addDay()->setTime(9, 0));
        app(RoutineOccurrenceGenerator::class)->run();

        $cp = $routine->checkpoints->firstWhere('name', 'Comments');
        $oldest = RoutineOccurrence::query()->where('checkpoint_id', $cp->id)->orderBy('due_on')->firstOrFail();

        $this->actingAs($sanjai)->postJson(route('inbox-desk.check', $oldest->id))->assertOk();

        $colleague = User::factory()->employee()->create();
        $routine->users()->attach($colleague);
        $this->actingAs($colleague)->postJson(route('inbox-desk.undo', $oldest->id))->assertForbidden();

        $this->actingAs($sanjai)->postJson(route('inbox-desk.undo', $oldest->id))->assertOk();

        $this->assertSame(2, RoutineOccurrence::query()->where('checkpoint_id', $cp->id)->open()->count());
    }

    public function test_an_admin_sees_the_board_without_being_on_the_routine(): void
    {
        [$routine, $sanjai] = $this->deskRoutine(['brand_a']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $open = RoutineOccurrence::query()->open()->firstOrFail();

        $this->actingAs($sanjai)->postJson(route('inbox-desk.check', $open->id), ['count' => 2])->assertOk();

        $board = $this->actingAs($admin)->getJson(route('inbox-desk.state'))->assertOk()->json();
        $this->assertSame(1, $board['totals']['done']);
        $this->assertSame($sanjai->name, $board['feed'][0]['by']);
        $this->assertTrue($board['is_admin']);
    }

    public function test_new_dms_are_counted_only_for_accounts_whose_webhooks_flow(): void
    {
        [$routine, $sanjai] = $this->deskRoutine(['quiet', 'busy']);
        $busy = SocialAccount::query()->where('username', 'busy')->firstOrFail();

        foreach (['1001', '1001', '1002'] as $i => $sender) {
            $this->dmEvent($busy, $sender, 'mid-'.$i);
        }
        // The account's own outgoing reply is not somebody waiting.
        $this->dmEvent($busy, $busy->platform_user_id, 'mid-echo', echo: true);

        $board = $this->actingAs($sanjai)->getJson(route('inbox-desk.state'))->json();
        $dms = $routine->checkpoints->firstWhere('name', 'DMs')->id;
        $accounts = collect($board['routines'][0]['accounts'])->keyBy('handle');

        $this->assertSame(2, $accounts['@busy']['activity'][$dms]['count']);
        $this->assertSame('2 new chats', $accounts['@busy']['activity'][$dms]['label']);
        $this->assertSame([], $accounts['@quiet']['activity'], 'no feed is no hint, never "0 new"');

        // Checking the inbox is what "new" is measured from.
        Carbon::setTestNow(now()->addMinute());
        $after = $this->actingAs($sanjai)
            ->postJson(route('inbox-desk.check', $accounts['@busy']['cells'][$dms]['id']), ['count' => 2])
            ->json('board');
        $busyAfter = collect($after['routines'][0]['accounts'])->firstWhere('handle', '@busy');
        $this->assertSame('Nothing new', $busyAfter['activity'][$dms]['label']);
    }

    public function test_the_phone_widget_carries_the_inbox_tally_for_people_on_it(): void
    {
        [$routine, $sanjai] = $this->deskRoutine(['brand_a', 'brand_b']);
        $open = RoutineOccurrence::query()->open()->firstOrFail();
        $this->actingAs($sanjai)->postJson(route('inbox-desk.check', $open->id))->assertOk();

        $key = WidgetToken::issue($sanjai, 'Phone')['plain'];
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/widget/today')
            ->assertOk()
            ->assertJsonPath('inbox.done', 1)
            ->assertJsonPath('inbox.total', 4)
            ->assertJsonPath('inbox.accounts_left', 2)
            ->assertJsonPath('inbox.accounts.0.checks.0.short', 'DMs');

        $other = User::factory()->employee()->create();
        $this->withHeader('Authorization', 'Bearer '.WidgetToken::issue($other, 'Phone')['plain'])
            ->getJson('/api/widget/today')
            ->assertOk()
            ->assertJsonMissingPath('inbox');
    }

    public function test_my_routines_points_at_the_inbox_check_instead_of_listing_accounts(): void
    {
        [$routine, $sanjai] = $this->deskRoutine(['brand_a']);

        $this->actingAs($sanjai)->get(route('my.routines'))
            ->assertOk()
            ->assertSee(route('inbox-desk.index'))
            ->assertSee('2 checks left')
            ->assertDontSee('@brand_a');
    }

    /**
     * A daily, shared, per-account routine shaped like the real one: DMs and
     * Comments checkpoints, a reply-count field on each.
     *
     * @param  list<string>  $usernames
     * @return array{0: Routine, 1: User}
     */
    private function deskRoutine(array $usernames): array
    {
        $sanjai = User::factory()->employee()->create(['name' => 'Sanjai']);

        $routine = Routine::factory()->create([
            'title' => 'Instagram DMs & Comments',
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
            'catch_up_days' => 7,
            'starts_on' => today()->toDateString(),
        ]);
        $routine->users()->attach($sanjai);

        foreach (['DMs' => 'replied_dms', 'Comments' => 'replied_comments'] as $i => $key) {
            $cp = $routine->checkpoints()->create(['name' => $i, 'sort_order' => 0]);
            $routine->fields()->create([
                'checkpoint_id' => $cp->id,
                'label' => 'Replied to',
                'key' => $key,
                'type' => RoutineField::TYPE_NUMBER,
                'default_value' => '0',
                'sort_order' => 0,
            ]);
        }

        foreach ($usernames as $i => $username) {
            $account = SocialAccount::create([
                'client_id' => Client::factory()->create()->id,
                'platform' => SocialAccount::PLATFORM_INSTAGRAM,
                'platform_user_id' => (string) (900 + $i),
                'username' => $username,
                'status' => SocialAccount::STATUS_CONNECTED,
            ]);
            $account->forceFill(['access_token' => 'IGQV-test', 'connected_at' => now()])->save();

            $routine->subjects()->create(['subject_type' => Routine::SUBJECT_SOCIAL, 'subject_id' => $account->id]);
        }

        app(RoutineOccurrenceGenerator::class)->run();

        return [$routine->fresh(['checkpoints', 'fields']), $sanjai];
    }

    private function dmEvent(SocialAccount $account, string $sender, string $mid, bool $echo = false): void
    {
        SocialWebhookEvent::create([
            'platform' => 'instagram',
            'social_account_id' => $account->id,
            'object' => 'instagram',
            'field' => null,
            'external_id' => $account->platform_user_id,
            'dedupe_key' => hash('sha256', $mid),
            'payload' => [
                'sender' => ['id' => $sender],
                'recipient' => ['id' => $echo ? '1001' : $account->platform_user_id],
                'message' => array_filter(['mid' => $mid, 'text' => 'hi', 'is_echo' => $echo ?: null]),
            ],
            'received_at' => now(),
        ]);
    }
}
