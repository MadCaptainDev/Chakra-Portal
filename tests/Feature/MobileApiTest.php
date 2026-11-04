<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\PushSetting;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Chakra mobile app API (routes/mobile-api.php, docs/chakra-mobile/API.md).
 */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai Kumar', 'email' => 'sanjai@chakra.test', 'password' => bcrypt('secret-pass')]);
    }

    private function token(User $user): string
    {
        return $this->postJson(route('api.mobile.login'), [
            'email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'Sanjai’s iPhone', 'platform' => 'ios', 'app_version' => '1.0.0',
        ])->assertCreated()->json('token');
    }

    private function ping(User $user, string $title = 'New to-do', string $url = '/my/todos'): void
    {
        $user->notify(new class($title, $url) extends Notification
        {
            public function __construct(private string $title, private string $url) {}

            public function via(object $notifiable): array
            {
                return [FcmChannel::class];
            }

            public function toFcm(object $notifiable): PushMessage
            {
                return new PushMessage($this->title, 'Edit the Squad reel', $this->url, 'todo-1');
            }
        });
    }

    public function test_login_returns_a_token_and_the_profile(): void
    {
        $response = $this->postJson(route('api.mobile.login'), [
            'email' => 'sanjai@chakra.test', 'password' => 'secret-pass', 'device_name' => 'iPhone 15', 'platform' => 'ios',
        ])->assertCreated();

        $this->assertStringStartsWith('chakraplanner_', $response->json('token'));
        $response->assertJsonPath('user.first_name', 'Sanjai')
            ->assertJsonPath('features.today', true)
            ->assertJsonPath('features.planner', false)
            ->assertJsonPath('unread_notifications', 0);
        $this->assertContains('list_todos', $response->json('actions'));
        $this->assertDatabaseHas('planner_sessions', ['user_id' => $this->staff->id, 'platform' => 'ios-app', 'device_name' => 'iPhone 15']);
    }

    public function test_bad_password_and_clients_are_refused(): void
    {
        $this->postJson(route('api.mobile.login'), ['email' => 'sanjai@chakra.test', 'password' => 'nope', 'device_name' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'password' => bcrypt('secret-pass')]);
        $this->postJson(route('api.mobile.login'), ['email' => $client->email, 'password' => 'secret-pass', 'device_name' => 'x'])
            ->assertForbidden()->assertJsonPath('code', 'no_access');
    }

    public function test_every_endpoint_needs_a_token_and_logout_ends_it(): void
    {
        $this->getJson(route('api.mobile.me'))->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        $token = $this->token($this->staff);
        $this->withToken($token)->getJson(route('api.mobile.me'))->assertOk()->assertJsonPath('user.email', 'sanjai@chakra.test');
        $this->withToken($token)->postJson(route('api.mobile.logout'))->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.mobile.me'))->assertUnauthorized();
    }

    public function test_today_is_the_widget_summary(): void
    {
        $token = $this->token($this->staff);

        $this->withToken($token)->getJson(route('api.mobile.today'))
            ->assertOk()
            ->assertJsonPath('name', 'Sanjai')
            ->assertJsonStructure(['date', 'date_label', 'generated_at', 'day' => ['date', 'label'], 'shoots', 'todos' => ['count', 'items', 'open']]);
    }

    public function test_notifications_land_in_the_inbox_even_without_push(): void
    {
        $this->ping($this->staff, 'First');
        $this->ping($this->staff, 'Second', 'http://localhost/shoots/4?tab=crew');
        $token = $this->token($this->staff);

        $list = $this->withToken($token)->getJson(route('api.mobile.notifications.index'))->assertOk();
        $list->assertJsonPath('unread', 2)
            ->assertJsonPath('data.0.title', 'Second')
            ->assertJsonPath('data.0.path', '/shoots/4?tab=crew')
            ->assertJsonPath('data.0.url', 'http://localhost/shoots/4?tab=crew')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('data.1.type', fn ($type) => is_string($type) && $type !== '');

        $latest = $list->json('latest_id');
        $this->withToken($token)->getJson(route('api.mobile.notifications.index', ['after' => $latest]))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->withToken($token)->postJson(route('api.mobile.notifications.read'), ['ids' => [$latest]])
            ->assertOk()->assertJsonPath('marked', 1)->assertJsonPath('unread', 1);
        $this->withToken($token)->postJson(route('api.mobile.notifications.read'))
            ->assertOk()->assertJsonPath('unread', 0);
    }

    public function test_nobody_reads_someone_elses_inbox_and_clients_get_none(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->ping($other);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->ping($client);

        $this->assertSame(0, AppNotification::query()->where('user_id', $client->id)->count());
        $this->withToken($this->token($this->staff))->getJson(route('api.mobile.notifications.index'))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_push_token_registers_as_a_native_device_and_gets_a_visible_banner(): void
    {
        $token = $this->token($this->staff);
        $this->withToken($token)->postJson(route('api.mobile.push-token.store'), ['token' => str_repeat('a', 40), 'platform' => 'ios', 'device_name' => 'iPhone 15'])
            ->assertCreated()->assertJsonPath('push_configured', false);

        $pushToken = PushToken::query()->where('user_id', $this->staff->id)->sole();
        $this->assertSame('ios_app', $pushToken->device_kind);
        $this->assertTrue($pushToken->isNativeApp());

        PushSetting::current()->update([
            'service_account_json' => json_encode(['type' => 'service_account', 'project_id' => 'p', 'client_email' => 'x@p.iam.gserviceaccount.com', 'private_key' => $this->pem()]),
            'web_config' => json_encode(['projectId' => 'p']),
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.t', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/1']),
        ]);

        PushSender::make()->send(collect([$pushToken]), new PushMessage('Shoot in 30 min', 'Squad Architects', '/shoots/4', 'shoot-4'));

        Http::assertSent(function (HttpRequest $request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return true;
            }
            $message = $request->data()['message'];
            $this->assertSame('Shoot in 30 min', $message['notification']['title']);
            $this->assertSame('/shoots/4', $message['data']['url']);
            $this->assertSame('default', $message['apns']['payload']['aps']['sound']);
            $this->assertArrayNotHasKey('webpush', $message);

            return true;
        });

        $this->withToken($token)->deleteJson(route('api.mobile.push-token.destroy'), ['token' => str_repeat('a', 40)])->assertNoContent();
        $this->assertSame(0, PushToken::query()->count());
    }

    public function test_actions_list_and_run_with_the_persons_permissions(): void
    {
        $token = $this->token($this->staff);

        $names = collect($this->withToken($token)->getJson(route('api.mobile.actions.index'))->assertOk()->json('data'))->pluck('name');
        $this->assertContains('list_todos', $names);
        $this->assertNotContains('run_query', $names); // admin-only

        $this->withToken($token)->postJson(route('api.mobile.actions.run', 'list_todos'), [])
            ->assertOk()->assertJsonPath('ok', true);

        $this->withToken($token)->postJson(route('api.mobile.actions.run', 'run_query'), ['sql' => 'select 1'])
            ->assertNotFound()->assertJsonPath('ok', false);
    }

    private function adminToken(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Vasan Admin', 'password' => bcrypt('secret-pass')]);

        return [$admin, $this->token($admin)];
    }

    private function act(string $token, string $name, array $args = [], int $status = 200): array
    {
        return $this->withToken($token)->postJson(route('api.mobile.actions.run', $name), $args)->assertStatus($status)->json();
    }

    public function test_me_carries_permissions_and_admin_flags(): void
    {
        [, $token] = $this->adminToken();
        $me = $this->withToken($token)->getJson(route('api.mobile.me'))->assertOk();
        $me->assertJsonPath('features.admin_area', true);
        $this->assertContains('edit', $me->json('permissions.shoots'));
        $this->assertContains('approve', $me->json('permissions.invoices'));
        foreach (['update_shoot', 'run_shoot', 'record_invoice_payment', 'approve_invoice', 'send_invoice_whatsapp', 'list_team'] as $action) {
            $this->assertContains($action, $me->json('actions'));
        }

        $staff = $this->withToken($this->token($this->staff))->getJson(route('api.mobile.me'))->assertOk();
        $staff->assertJsonPath('features.admin_area', false);
        $this->assertNotContains('update_shoot', $staff->json('actions'));
        $this->assertContains('run_shoot', $staff->json('actions'));
        $this->assertContains('list_team', $staff->json('actions'));
    }

    public function test_an_admin_edits_runs_and_wraps_a_shoot(): void
    {
        [, $token] = $this->adminToken();
        $client = \App\Models\Client::create(['name' => 'Squad Architects']);
        $made = $this->act($token, 'create_shoot', ['title' => 'Office reel', 'starts_at' => now()->addDay()->format('Y-m-d').' 10:00'])['result'];
        $this->act($token, 'assign_shoot_crew', ['shoot_id' => $made['shoot_id'], 'person' => 'Sanjai Kumar']);

        $updated = $this->act($token, 'update_shoot', [
            'shoot_id' => $made['shoot_id'], 'status' => 'confirmed', 'location' => 'Anna Nagar',
            'client' => (string) $client->id, 'starts_at' => now()->addDay()->format('Y-m-d').' 11:30', 'notes' => 'Bring the gimbal',
        ])['result'];
        $this->assertSame('confirmed', $updated['shoot']['status']);
        $this->assertSame('Squad Architects', $updated['shoot']['client']);
        $this->assertStringEndsWith('11:30', $updated['shoot']['starts_at']);
        $this->assertSame('Sanjai Kumar', $updated['shoot']['crew'][0]['name']);

        $this->assertSame('Sanjai Kumar', $this->act($token, 'update_shoot', ['shoot_id' => $made['shoot_id'], 'remove_crew' => 'Sanjai'])['result']['removed_from_crew']);
        $this->act($token, 'update_shoot', ['shoot_id' => $made['shoot_id'], 'status' => 'live'], 422);
        $this->act($token, 'update_shoot', ['shoot_id' => $made['shoot_id']], 422);

        $this->assertTrue($this->act($token, 'run_shoot', ['shoot_id' => $made['shoot_id'], 'action' => 'start'])['result']['shoot']['live']);
        $wrapped = $this->act($token, 'run_shoot', ['shoot_id' => $made['shoot_id'], 'action' => 'wrap'])['result']['shoot'];
        $this->assertFalse($wrapped['live']);
        $this->assertSame('completed', $wrapped['status']);

        // Crew who are not on it cannot run it; staff cannot edit shoots at all.
        $staffToken = $this->token($this->staff);
        $this->act($staffToken, 'run_shoot', ['shoot_id' => $made['shoot_id'], 'action' => 'start'], 422);
        $this->act($staffToken, 'update_shoot', ['shoot_id' => $made['shoot_id'], 'status' => 'planned'], 404);
    }

    public function test_an_admin_records_a_payment_until_the_invoice_is_paid(): void
    {
        [, $token] = $this->adminToken();
        $client = \App\Models\Client::create(['name' => 'Riya Makeover']);
        $made = $this->act($token, 'create_invoice', [
            'client' => (string) $client->id, 'invoice_date' => now()->toDateString(),
            'items' => [['description' => 'Reels', 'quantity' => 2, 'unit_price' => 5000]],
        ])['result'];
        $id = (string) ($made['invoice']['invoice_id'] ?? $made['invoice_id']);
        if (($made['invoice']['status'] ?? $made['status'] ?? null) === \App\Models\Invoice::STATUS_PENDING_APPROVAL) {
            $this->act($token, 'approve_invoice', ['invoice' => $id]);
        }

        $this->act($token, 'record_invoice_payment', ['invoice' => $id, 'amount' => 20000], 422);
        $part = $this->act($token, 'record_invoice_payment', ['invoice' => $id, 'amount' => 4000, 'method' => 'UPI'])['result'];
        $this->assertEquals(6000, $part['invoice']['balance_due']);
        $full = $this->act($token, 'record_invoice_payment', ['invoice' => $id])['result'];
        $this->assertEquals(6000, $full['recorded']);
        $this->assertSame(\App\Models\Invoice::STATUS_PAID, $full['invoice']['status']);
        $this->act($token, 'record_invoice_payment', ['invoice' => $id], 422);
    }

    public function test_the_team_list_and_client_messaging_flags(): void
    {
        [, $token] = $this->adminToken();
        \App\Models\User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'A Client']);
        $team = collect($this->act($token, 'list_team')['result']['team'])->pluck('name');
        $this->assertContains('Sanjai Kumar', $team);
        $this->assertNotContains('A Client', $team);

        $actions = collect($this->withToken($token)->getJson(route('api.mobile.actions.index'))->json('data'))->keyBy('name');
        $this->assertTrue($actions['send_invoice_whatsapp']['messages_client']);
        $this->assertFalse($actions['record_invoice_payment']['messages_client']);
    }

    public function test_invoice_and_client_lists_and_one_shoot(): void
    {
        [, $token] = $this->adminToken();
        $client = \App\Models\Client::create(['name' => 'Riya Makeover', 'phone' => '9876543210']);
        $made = $this->act($token, 'create_invoice', [
            'client' => (string) $client->id, 'invoice_date' => now()->toDateString(),
            'items' => [['description' => 'Reels', 'quantity' => 1, 'unit_price' => 8000]],
        ])['result'];
        $id = (string) ($made['invoice']['invoice_id'] ?? $made['invoice_id']);
        if (\App\Models\Invoice::find($id)->isPendingApproval()) {
            $this->act($token, 'approve_invoice', ['invoice' => $id]);
        }
        $this->act($token, 'record_invoice_payment', ['invoice' => $id, 'amount' => 3000]);

        $open = $this->act($token, 'list_invoices')['result'];
        $this->assertSame(1, $open['count']);
        $this->assertSame('partial', $open['invoices'][0]['status']);
        $this->assertEquals(5000, $open['balance_due_shown']);
        $this->assertSame(0, $this->act($token, 'list_invoices', ['status' => 'paid'])['result']['count']);

        $clients = $this->act($token, 'list_clients', ['search' => 'riya'])['result']['clients'];
        $this->assertSame($client->id, $clients[0]['id']);

        $shoot = $this->act($token, 'create_shoot', ['title' => 'Salon reel', 'starts_at' => now()->addDay()->format('Y-m-d').' 09:00', 'client' => (string) $client->id])['result'];
        $got = $this->act($token, 'get_shoot', ['shoot_id' => $shoot['shoot_id']])['result'];
        $this->assertSame('Riya Makeover', $got['client']);
        $this->assertFalse($got['live']);

        // Staff not on the crew cannot read it, nor the invoices.
        $staffToken = $this->token($this->staff);
        $this->act($staffToken, 'get_shoot', ['shoot_id' => $shoot['shoot_id']], 422);
        $this->act($staffToken, 'list_invoices', [], 404);
    }

    private function pem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
