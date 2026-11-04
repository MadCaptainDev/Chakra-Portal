<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\LiveActivityToken;
use App\Models\PushSetting;
use App\Models\PushToken;
use App\Models\Shoot;
use App\Models\User;
use App\Services\LiveActivities\EditingBoardActivity;
use App\Services\Push\ApnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Direct APNs push and Live Activities for the iPhone app. */
class LiveActivitiesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $crew;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Vasan', 'password' => bcrypt('secret-pass')]);
        $this->crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai Kumar', 'password' => bcrypt('secret-pass')]);
    }

    private function token(User $user): string
    {
        return $this->postJson(route('api.mobile.login'), ['email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'iPhone', 'platform' => 'ios'])->json('token');
    }

    private bool $appleSaysGone = false;

    private function configureApns(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        PushSetting::current()->update(['apns_key_p8' => $pem, 'apns_key_id' => 'ABC123DEFG', 'apns_team_id' => 'P7LS2WCL9A', 'apns_bundle_id' => 'in.chakragroups.chakra']);
        Http::fake(['*push.apple.com/*' => fn () => $this->appleSaysGone ? Http::response(['reason' => 'Unregistered'], 410) : Http::response('', 200)]);
    }

    /** @return list<array{url: string, headers: array, body: array}> */
    private function applePushes(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'push.apple.com'))
            ->map(fn ($pair) => ['url' => $pair[0]->url(), 'headers' => $pair[0]->headers(), 'body' => json_decode($pair[0]->body(), true)])
            ->values()->all();
    }

    private function shootWithCrew(): Shoot
    {
        $client = \App\Models\Client::create(['name' => 'Squad Architects']);
        $shoot = Shoot::create(['title' => 'Office reel', 'client_id' => $client->id, 'starts_at' => now()->addHour(), 'status' => 'confirmed', 'location' => 'Anna Nagar']);
        $shoot->crew()->create(['user_id' => $this->crew->id, 'role' => 'Camera']);

        return $shoot;
    }

    public function test_the_provider_token_is_an_es256_jwt(): void
    {
        $this->configureApns();
        $parts = explode('.', ApnsClient::make()->jwt());
        $this->assertCount(3, $parts);
        $this->assertSame(['alg' => 'ES256', 'kid' => 'ABC123DEFG'], json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true));
        $this->assertSame(64, strlen(base64_decode(strtr($parts[2], '-_', '+/').'==')));
    }

    public function test_live_activity_tokens_register_replace_and_delete(): void
    {
        $token = $this->token($this->admin);
        $shoot = $this->shootWithCrew();
        $this->withToken($token)->postJson(route('api.mobile.live-activity-tokens.store'), ['type' => 'push_to_start', 'activity' => 'CrewTrip', 'token' => str_repeat('ab', 32), 'environment' => 'sandbox'])
            ->assertCreated()->assertJsonPath('apns_configured', false);
        $this->withToken($token)->postJson(route('api.mobile.live-activity-tokens.store'), ['type' => 'update', 'activity' => 'CrewTrip', 'token' => str_repeat('cd', 32), 'activity_id' => 'A1', 'ref' => ['shoot_id' => $shoot->id]])->assertCreated();
        // The same activity rotates its token: the old one goes.
        $this->withToken($token)->postJson(route('api.mobile.live-activity-tokens.store'), ['type' => 'update', 'activity' => 'CrewTrip', 'token' => str_repeat('ef', 32), 'activity_id' => 'A1', 'ref' => ['shoot_id' => $shoot->id]])->assertCreated();
        $this->assertSame(2, LiveActivityToken::count());
        $this->withToken($token)->postJson(route('api.mobile.live-activity-tokens.store'), ['type' => 'update', 'activity' => 'Nope', 'token' => 'zz'])->assertStatus(422);

        $this->withToken($token)->deleteJson(route('api.mobile.live-activity-tokens.destroy'), ['activity_id' => 'A1'])->assertNoContent();
        $this->assertSame(1, LiveActivityToken::count());
    }

    public function test_crew_on_the_way_notifies_and_starts_then_updates_the_admins_crew_trip(): void
    {
        $this->configureApns();
        $shoot = $this->shootWithCrew();
        $admin = $this->token($this->admin);
        $this->withToken($admin)->postJson(route('api.mobile.live-activity-tokens.store'), ['type' => 'push_to_start', 'activity' => 'CrewTrip', 'token' => str_repeat('ab', 32)])->assertCreated();

        $crew = $this->token($this->crew);
        $result = $this->withToken($crew)->postJson(route('api.mobile.actions.run', 'crew_status'), ['shoot_id' => $shoot->id, 'status' => 'on_the_way', 'eta_minutes' => 25])
            ->assertOk()->json('result');
        $this->assertStringStartsWith('Sanjai Kumar is on the way to Squad Architects · ETA ', $result['message']);
        $this->assertSame('on_the_way', $result['shoot']['crew'][0]['crew_status']);
        $this->assertNotNull($shoot->fresh()->departed_at);

        $note = AppNotification::query()->where('user_id', $this->admin->id)->sole();
        $this->assertSame('crew_trip_updated', $note->type);

        $push = collect($this->applePushes())->sole();
        $this->assertStringEndsWith('/3/device/'.str_repeat('ab', 32), $push['url']);
        $this->assertSame(['liveactivity'], $push['headers']['apns-push-type']);
        $this->assertSame(['in.chakragroups.chakra.push-type.liveactivity'], $push['headers']['apns-topic']);
        $aps = $push['body']['aps'];
        $this->assertSame('start', $aps['event']);
        $this->assertSame('CrewTripAttributes', $aps['attributes-type']);
        $this->assertSame(['shootId' => $shoot->id, 'title' => 'Office reel', 'client' => 'Squad Architects', 'location' => 'Anna Nagar'], $aps['attributes']);
        $this->assertSame('Sanjai Kumar', $aps['content-state']['crew'][0]['name']);
        $this->assertIsInt($aps['content-state']['crew'][0]['etaAt']);
        $this->assertArrayHasKey('alert', $aps);

        // The phone started it and registered the activity's update token: next time it is an update.
        $this->withToken($admin)->postJson(route('api.mobile.live-activity-tokens.store'), ['type' => 'update', 'activity' => 'CrewTrip', 'token' => str_repeat('cd', 32), 'activity_id' => 'X', 'ref' => ['shoot_id' => $shoot->id]])->assertCreated();
        $this->withToken($crew)->postJson(route('api.mobile.actions.run', 'crew_status'), ['shoot_id' => $shoot->id, 'status' => 'arrived'])->assertOk();
        $last = collect($this->applePushes())->last();
        $this->assertStringEndsWith(str_repeat('cd', 32), $last['url']);
        $this->assertSame('update', $last['body']['aps']['event']);
        $this->assertSame('arrived', $last['body']['aps']['content-state']['crew'][0]['status']);
        $this->assertNotNull($shoot->fresh()->reached_at);

        // Wrapped (not live): the activity ends with a dismissal date.
        $this->withToken($crew)->postJson(route('api.mobile.actions.run', 'crew_status'), ['shoot_id' => $shoot->id, 'status' => 'wrapped'])->assertOk();
        $end = collect($this->applePushes())->last();
        $this->assertSame('end', $end['body']['aps']['event']);
        $this->assertIsInt($end['body']['aps']['dismissal-date']);
        $this->assertNotNull(LiveActivityToken::query()->where('type', 'update')->sole()->ended_at);
    }

    public function test_only_crew_can_report_and_today_shows_crew_status(): void
    {
        $shoot = $this->shootWithCrew();
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'password' => bcrypt('secret-pass')]);
        $this->withToken($this->token($other))->postJson(route('api.mobile.actions.run', 'crew_status'), ['shoot_id' => $shoot->id, 'status' => 'arrived'])->assertStatus(422);

        $crew = $this->token($this->crew);
        $this->withToken($crew)->postJson(route('api.mobile.actions.run', 'crew_status'), ['shoot_id' => $shoot->id, 'status' => 'on_the_way', 'eta_minutes' => 10])->assertOk();
        $item = $this->withToken($crew)->getJson(route('api.mobile.today'))->assertOk()->json('shoots.items.0');
        $this->assertSame('on_the_way', $item['crew'][0]['crew_status']);
        $this->assertTrue($item['crew'][0]['is_you']);
    }

    public function test_an_editing_change_starts_the_board_once_a_day_then_updates_it(): void
    {
        $this->configureApns();
        LiveActivityToken::create(['user_id' => $this->admin->id, 'type' => 'push_to_start', 'activity' => 'EditingBoard', 'token' => str_repeat('ab', 32), 'token_hash' => hash('sha256', str_repeat('ab', 32))]);

        $move = ['person' => 'Priya', 'label' => 'Done → review', 'title' => 'Diwali offer'];
        EditingBoardActivity::changed($move);
        EditingBoardActivity::changed($move); // started a moment ago: no second start
        $pushes = $this->applePushes();
        $this->assertCount(1, $pushes);
        $aps = $pushes[0]['body']['aps'];
        $this->assertSame('start', $aps['event']);
        $this->assertSame('EditingBoardAttributes', $aps['attributes-type']);
        $this->assertSame(['title' => 'Editing board'], $aps['attributes']);
        $this->assertSame('Priya · Done → review: Diwali offer', $aps['content-state']['latest']);
        foreach (['inProgress', 'underReview', 'readyToPost', 'todo', 'latestAt'] as $key) {
            $this->assertIsInt($aps['content-state'][$key]);
        }

        LiveActivityToken::create(['user_id' => $this->admin->id, 'type' => 'update', 'activity' => 'EditingBoard', 'token' => str_repeat('cd', 32), 'token_hash' => hash('sha256', str_repeat('cd', 32)), 'activity_id' => 'EB']);
        EditingBoardActivity::changed($move);
        $this->assertSame('update', collect($this->applePushes())->last()['body']['aps']['event']);

        $this->artisan('live-activities:end-day')->assertSuccessful();
        $this->assertSame('end', collect($this->applePushes())->last()['body']['aps']['event']);
    }

    public function test_ordinary_notifications_go_to_apns_tokens_and_dead_tokens_are_pruned(): void
    {
        $this->configureApns();
        $token = $this->token($this->crew);
        $this->withToken($token)->postJson(route('api.mobile.push-token.store'), ['token' => str_repeat('ab', 32), 'platform' => 'ios', 'kind' => 'apns', 'environment' => 'sandbox'])
            ->assertCreated()->assertJsonPath('push_configured', true);
        $this->assertSame(PushToken::KIND_APNS, PushToken::sole()->device_kind);

        $shoot = $this->shootWithCrew();
        $this->withToken($this->token($this->admin))->postJson(route('api.mobile.actions.run', 'crew_status'), ['shoot_id' => $shoot->id, 'status' => 'arrived', 'person' => 'Sanjai'])->assertOk();
        // Sanjai reported "for himself" via the admin, so he is not notified; send one directly instead.
        $this->crew->notify(new \App\Notifications\CrewTripUpdated($shoot, 'Test'));
        $push = collect($this->applePushes())->first(fn ($p) => $p['headers']['apns-push-type'] === ['alert']);
        $this->assertStringContainsString('api.sandbox.push.apple.com', $push['url']);
        $this->assertSame('in.chakragroups.chakra', $push['headers']['apns-topic'][0]);
        $this->assertSame('Test', $push['body']['aps']['alert']['body']);
        $this->assertStringContainsString('/shoots/'.$shoot->id.'/call-sheet', $push['body']['url']);

        $this->appleSaysGone = true;
        $this->crew->notify(new \App\Notifications\CrewTripUpdated($shoot, 'Again'));
        $this->assertSame(0, PushToken::count());
    }

    public function test_an_admin_saves_the_apple_key(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $this->actingAs($this->admin)->put(route('push.apns'), ['apns_key_p8' => 'nonsense', 'apns_key_id' => 'ABC123DEFG', 'apns_team_id' => 'P7LS2WCL9A', 'apns_bundle_id' => 'in.chakragroups.chakra'])
            ->assertSessionHasErrors('apns_key_p8');
        $this->actingAs($this->admin)->put(route('push.apns'), ['apns_key_p8' => $pem, 'apns_key_id' => 'ABC123DEFG', 'apns_team_id' => 'P7LS2WCL9A', 'apns_bundle_id' => 'in.chakragroups.chakra'])
            ->assertRedirect(route('push.edit'));
        $this->assertTrue(PushSetting::current()->fresh()->apnsConfigured());
        $this->actingAs($this->admin)->get(route('push.edit'))->assertOk()->assertSee('ABC123DEFG');
    }
}
