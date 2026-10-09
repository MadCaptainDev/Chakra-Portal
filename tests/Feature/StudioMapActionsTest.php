<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\Todo;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Services\StudioMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioMapActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Admin']);
    }

    private function staff(string $name): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => $name]);
    }

    private function shoot(Client $client, array $attributes = []): Shoot
    {
        return Shoot::create(array_merge([
            'title' => 'SVA reels', 'client_id' => $client->id,
            'starts_at' => now()->addDay()->setTime(10, 0), 'status' => Shoot::STATUS_CONFIRMED,
        ], $attributes));
    }

    private function whatsapp(): void
    {
        WhatsappSetting::current()->update(['access_token' => 'EAAG-test-token', 'phone_number_id' => '556677889900']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);
    }

    // ——— Who may ———

    public function test_only_admins_can_act_from_the_map(): void
    {
        $employee = $this->staff('Nitis');
        $shoot = $this->shoot(Client::factory()->create());

        $this->actingAs($employee)
            ->postJson(route('studio-map.actions.assign-crew'), ['shoot_id' => $shoot->id, 'user_id' => $employee->id])
            ->assertForbidden();
        $this->actingAs($employee)
            ->postJson(route('studio-map.actions.send'), ['kind' => 'brief', 'id' => $shoot->client_id])
            ->assertForbidden();

        $this->assertSame(0, $shoot->crew()->count());
    }

    // ——— Crew ———

    public function test_dropping_a_person_on_a_shoot_puts_them_on_the_crew_through_the_tool(): void
    {
        $sanjai = $this->staff('Sanjai K');
        $this->staff('Sanjai R'); // same first name: the map sends an id, so no ambiguity
        $shoot = $this->shoot(Client::factory()->create());

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.assign-crew'), ['shoot_id' => $shoot->id, 'user_id' => $sanjai->id, 'role' => 'Director', 'call_time' => '08:30'])
            ->assertOk()
            ->assertJsonPath('message', 'Sanjai K added to SVA reels.');

        $row = $shoot->crew()->sole();
        $this->assertSame($sanjai->id, $row->user_id);
        $this->assertSame('Director', $row->role);
    }

    public function test_a_same_day_clash_comes_back_as_a_warning(): void
    {
        $sanjai = $this->staff('Sanjai K');
        $client = Client::factory()->create();
        $first = $this->shoot($client, ['title' => 'Morning']);
        ShootCrew::create(['shoot_id' => $first->id, 'user_id' => $sanjai->id]);
        $second = $this->shoot($client, ['title' => 'Evening', 'starts_at' => now()->addDay()->setTime(18, 0)]);

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.assign-crew'), ['shoot_id' => $second->id, 'user_id' => $sanjai->id])
            ->assertOk()
            ->assertJsonCount(1, 'warnings');
    }

    public function test_dragging_someone_to_the_tower_takes_them_off(): void
    {
        $sanjai = $this->staff('Sanjai K');
        $shoot = $this->shoot(Client::factory()->create());
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $sanjai->id]);

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.remove-crew'), ['shoot_id' => $shoot->id, 'user_id' => $sanjai->id])
            ->assertOk();
        $this->assertSame(0, $shoot->crew()->count());

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.remove-crew'), ['shoot_id' => $shoot->id, 'user_id' => $sanjai->id])
            ->assertStatus(422);
    }

    // ——— Shoots and to-dos ———

    public function test_booking_a_shoot_with_a_person_dropped_on_an_empty_building(): void
    {
        $client = Client::factory()->create(['name' => 'Zira']);
        $nitis = $this->staff('Nitis');

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.create-shoot'), [
                'client_id' => $client->id, 'title' => 'Zira launch',
                'starts_at' => now()->addDays(3)->format('Y-m-d').' 09:30', 'status' => 'confirmed',
                'crew_user_id' => $nitis->id, 'crew_role' => 'Camera',
            ])
            ->assertOk();

        $shoot = Shoot::where('title', 'Zira launch')->sole();
        $this->assertSame($client->id, $shoot->client_id);
        $this->assertSame(Shoot::STATUS_CONFIRMED, $shoot->status);
        $this->assertSame('Camera', $shoot->crew()->sole()->role);
    }

    public function test_a_shoot_in_the_past_is_refused_with_the_tools_own_words(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.create-shoot'), [
                'client_id' => Client::factory()->create()->id, 'title' => 'Too late',
                'starts_at' => now()->subDays(5)->format('Y-m-d').' 09:30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That start time is in the past. Check the date with the person.');

        $this->assertSame(0, Shoot::count());
    }

    public function test_a_to_do_lands_on_the_right_persons_board_against_the_client(): void
    {
        $client = Client::factory()->create(['name' => 'SVA Silks and Readymades', 'notion_venture' => 'SVA Silks']);
        $nitis = $this->staff('Nitis');

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.create-todo'), [
                'user_id' => $nitis->id, 'title' => 'Send the edited reels',
                'client_id' => $client->id, 'due_on' => today()->addDay()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'To-do for Nitis: Send the edited reels.');

        $todo = Todo::sole();
        $this->assertSame($nitis->id, $todo->user_id);
        $this->assertSame($this->admin->id, $todo->assigned_by_id);
        $this->assertSame('SVA Silks', $todo->venture);
    }

    // ——— WhatsApp: preview, then send ———

    public function test_the_invoice_preview_shows_exactly_what_will_be_sent_and_sends_nothing(): void
    {
        $this->whatsapp();
        $client = Client::factory()->create(['name' => 'Janet', 'phone' => '9876543210']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'invoice_number' => 'CP-0042', 'total' => 7500, 'due_date' => today()->subDays(10)]);

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.preview'), ['kind' => 'invoice', 'id' => $invoice->id])
            ->assertOk()
            ->assertJson([
                'to' => 'Janet',
                'phone' => '9876543210',
                'channel' => 'template',
                'message' => 'Hi Janet, your invoice CP-0042 for Rs. 7,500.00 is ready. Tap below to view or download it.',
            ]);

        Http::assertNothingSent();
    }

    public function test_sending_the_invoice_goes_through_the_same_path_as_the_invoice_page(): void
    {
        $this->whatsapp();
        $client = Client::factory()->create(['name' => 'Janet', 'phone' => '9876543210']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 7500]);

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.send'), ['kind' => 'invoice', 'id' => $invoice->id, 'phone' => '9876543210'])
            ->assertOk();

        Http::assertSent(fn ($r) => $r->data()['template']['name'] === Invoice::WHATSAPP_TEMPLATE && $r->data()['to'] === '919876543210');
        $this->assertNotNull($invoice->fresh()->whatsapp_sent_at);
        $this->assertSame(1, $invoice->whatsappLogs()->count());
    }

    public function test_an_invoice_awaiting_approval_cannot_be_previewed_or_sent(): void
    {
        $this->whatsapp();
        $invoice = Invoice::factory()->pendingApproval()->create();

        $this->actingAs($this->admin)->postJson(route('studio-map.actions.preview'), ['kind' => 'invoice', 'id' => $invoice->id])->assertStatus(422);
        $this->actingAs($this->admin)->postJson(route('studio-map.actions.send'), ['kind' => 'invoice', 'id' => $invoice->id, 'phone' => '9876543210'])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_brief_reminder_previews_then_sends_and_needs_a_phone(): void
    {
        $this->whatsapp();
        $client = Client::factory()->create(['name' => 'Zira', 'phone' => '9876543210']);
        ClientBrief::factory()->for($client)->create(['public_token' => 'abc123token']);
        $noPhone = Client::factory()->create(['name' => 'Silent', 'phone' => null]);

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.preview'), ['kind' => 'brief', 'id' => $client->id])
            ->assertOk()
            ->assertJsonPath('phone_editable', false)
            ->assertJsonPath('button', 'Open brief');
        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.preview'), ['kind' => 'brief', 'id' => $noPhone->id])
            ->assertStatus(422);
        Http::assertNothingSent();

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.send'), ['kind' => 'brief', 'id' => $client->id])
            ->assertOk()
            ->assertJsonPath('message', 'Brief reminder sent to Zira on WhatsApp.');
        Http::assertSentCount(1);
    }

    public function test_a_proposal_resend_needs_a_number(): void
    {
        $this->whatsapp();
        $client = Client::factory()->create(['phone' => null]);
        $proposal = Proposal::create(['client_id' => $client->id, 'title' => 'Plan', 'status' => Proposal::STATUS_SENT, 'sections' => []]);

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.send'), ['kind' => 'proposal', 'id' => $proposal->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Which number should it go to?');
        Http::assertNothingSent();

        $this->actingAs($this->admin)
            ->postJson(route('studio-map.actions.send'), ['kind' => 'proposal', 'id' => $proposal->id, 'phone' => '9876543210'])
            ->assertOk();
        Http::assertSent(fn ($r) => $r->data()['template']['name'] === Proposal::WHATSAPP_TEMPLATE);
    }

    // ——— What the map is told, so it can offer the right things ———

    public function test_the_snapshot_carries_what_each_action_needs(): void
    {
        $client = Client::factory()->create(['name' => 'Zira', 'is_active' => true, 'phone' => '9876543210']);
        $sanjai = $this->staff('Sanjai K');
        $shoot = $this->shoot($client);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $sanjai->id, 'role' => 'Director']);
        $this->shoot($client, ['title' => 'Too far', 'starts_at' => now()->addDays(20)]);
        Invoice::factory()->create(['client_id' => $client->id, 'total' => 5000, 'due_date' => today()->subDays(3)]);
        Proposal::create(['client_id' => $client->id, 'title' => 'Plan', 'status' => Proposal::STATUS_VIEWED, 'sections' => []]);
        ClientBrief::factory()->for($client)->create();
        Todo::create(['user_id' => $sanjai->id, 'title' => 'Cut the teaser', 'venture' => 'Zira', 'starts_on' => today(), 'due_on' => today()->subDay()]);

        $snapshot = StudioMap::snapshot();
        $b = collect($snapshot['buildings'])->firstWhere('name', 'Zira');

        $this->assertSame(['SVA reels'], array_column($b['upcoming_shoots'], 'title'), 'next fortnight only');
        $this->assertSame([['id' => $sanjai->id, 'name' => 'Sanjai K', 'role' => 'Director']], $b['upcoming_shoots'][0]['crew']);
        $this->assertSame(3, $b['unpaid_invoices'][0]['overdue_days']);
        $this->assertTrue($b['unpaid_invoices'][0]['sendable']);
        $this->assertSame('Plan', $b['open_proposals'][0]['title']);
        $this->assertTrue($b['brief_pending']);

        $me = collect($snapshot['team'])->firstWhere('name', 'Sanjai K');
        $this->assertSame(1, $me['open_todos']);
        $this->assertTrue($me['todos'][0]['overdue']);
    }

    public function test_the_page_renders_the_command_bar_and_dialog(): void
    {
        $this->actingAs($this->admin)->get(route('studio-map'))
            ->assertOk()
            ->assertSee('data-roster', false)
            ->assertSee('data-action-dialog', false)
            ->assertSee(str_replace('/', '\\/', route('studio-map.actions.preview')), false);
    }
}
