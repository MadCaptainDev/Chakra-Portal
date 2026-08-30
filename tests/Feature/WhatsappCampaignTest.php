<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappCampaignMessage;
use App\Models\User;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignLog;
use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Broadcast campaigns: the HTTP surface (create/store/show/send-now/cancel/
 * destroy, each gated the same way every other WhatsApp CRM resource is) and
 * whatsapp:dispatch-campaigns, the scheduled command that turns a `scheduled`
 * campaign into queued jobs. See SendWhatsappCampaignMessageJobTest for what
 * one of those jobs actually does once it runs.
 */
class WhatsappCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['whatsapp-crm' => $abilities]);

        return $user->refresh();
    }

    private function phonebookWithContacts(int $count): WhatsappPhonebook
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        for ($i = 0; $i < $count; $i++) {
            $contact = WhatsappContact::create(['phone' => '90000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
            $phonebook->contacts()->attach($contact);
        }

        return $phonebook;
    }

    /** @param array<string, mixed> $overrides */
    private function campaign(WhatsappPhonebook $phonebook, array $overrides = []): WhatsappCampaign
    {
        return WhatsappCampaign::create($overrides + [
            'name' => 'August Reminder',
            'meta_template_name' => 'shoot_reminder',
            'meta_template_language' => 'en_US',
            'phonebook_id' => $phonebook->id,
            'status' => 'draft',
        ]);
    }

    // -- index / create ---------------------------------------------------

    public function test_an_ungranted_employee_is_refused_the_index(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.campaigns.index'))->assertForbidden();
    }

    public function test_a_user_with_view_can_list_campaigns(): void
    {
        $this->campaign($this->phonebookWithContacts(1));

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.campaigns.index'))
            ->assertOk()
            ->assertSee('August Reminder');
    }

    public function test_the_module_dashboard_redirects_to_the_campaigns_index(): void
    {
        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.index'))
            ->assertRedirect(route('whatsapp-crm.campaigns.index'));
    }

    public function test_the_create_form_renders(): void
    {
        WhatsappPhonebook::create(['name' => 'Leads']);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.campaigns.create'))
            ->assertOk();
    }

    // -- store --------------------------------------------------------------

    public function test_creating_a_campaign_requires_the_create_ability(): void
    {
        $phonebook = $this->phonebookWithContacts(1);

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.campaigns.store'), [
                'name' => 'August Reminder',
                'meta_template_name' => 'shoot_reminder',
                'meta_template_language' => 'en_US',
                'phonebook_id' => $phonebook->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, WhatsappCampaign::count());
    }

    /**
     * The core fan-out: one log row per contact in the chosen phonebook, each
     * still pending -- nothing has been sent yet, store() only lays the
     * groundwork whatsapp:dispatch-campaigns will act on.
     */
    public function test_store_fans_out_one_log_per_phonebook_contact(): void
    {
        $phonebook = $this->phonebookWithContacts(3);

        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.campaigns.store'), [
                'name' => 'August Reminder',
                'meta_template_name' => 'shoot_reminder',
                'meta_template_language' => 'en_US',
                'phonebook_id' => $phonebook->id,
                'variable_mapping' => ['var1', 'Studio'],
            ]);

        $campaign = WhatsappCampaign::sole();
        $response->assertRedirect(route('whatsapp-crm.campaigns.show', $campaign));

        $this->assertSame(3, $campaign->logs()->count());
        $this->assertSame(3, $campaign->logs()->where('status', 'pending')->count());
        $this->assertSame(['var1', 'Studio'], $campaign->variable_mapping);
    }

    /**
     * No scheduled_at in the request is "send as soon as possible" -- the
     * campaign is scheduled for right now, not left in draft, so the very
     * next dispatch tick picks it up.
     */
    public function test_store_without_a_scheduled_at_schedules_the_campaign_for_now(): void
    {
        $phonebook = $this->phonebookWithContacts(1);

        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.campaigns.store'), [
                'name' => 'August Reminder',
                'meta_template_name' => 'shoot_reminder',
                'meta_template_language' => 'en_US',
                'phonebook_id' => $phonebook->id,
            ]);

        $campaign = WhatsappCampaign::sole();
        $this->assertSame('scheduled', $campaign->status);
        $this->assertNotNull($campaign->scheduled_at);
        $this->assertTrue($campaign->scheduled_at->lessThanOrEqualTo(now()));
    }

    public function test_store_with_a_scheduled_at_uses_the_given_time(): void
    {
        $phonebook = $this->phonebookWithContacts(1);
        $future = now()->addDay()->startOfMinute();

        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.campaigns.store'), [
                'name' => 'August Reminder',
                'meta_template_name' => 'shoot_reminder',
                'meta_template_language' => 'en_US',
                'phonebook_id' => $phonebook->id,
                'scheduled_at' => $future->format('Y-m-d\TH:i'),
            ]);

        $campaign = WhatsappCampaign::sole();
        $this->assertSame('scheduled', $campaign->status);
        $this->assertTrue($campaign->scheduled_at->equalTo($future));
    }

    // -- show / progress ------------------------------------------------------

    public function test_show_renders_the_campaign_and_its_progress(): void
    {
        $phonebook = $this->phonebookWithContacts(2);
        $campaign = $this->campaign($phonebook, ['status' => 'sending']);
        $campaign->logs()->create(['contact_id' => $phonebook->contacts()->first()->id, 'phone' => '900000000', 'status' => 'sent']);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('August Reminder');
    }

    public function test_the_progress_endpoint_returns_counts_and_status_as_json(): void
    {
        $phonebook = $this->phonebookWithContacts(2);
        $campaign = $this->campaign($phonebook, ['status' => 'sending']);
        $contact = $phonebook->contacts()->first();
        $campaign->logs()->create(['contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'sent']);
        $campaign->logs()->create(['contact_id' => $phonebook->contacts()->skip(1)->first()->id, 'phone' => '900000001', 'status' => 'pending']);

        $response = $this->actingAs($this->employee())
            ->getJson(route('whatsapp-crm.campaigns.progress', $campaign));

        $response->assertOk()->assertJson([
            'status' => 'sending',
            'total' => 2,
            'sent' => 1,
            'pending' => 1,
        ]);
    }

    // -- send-now / cancel / destroy ------------------------------------------

    public function test_send_now_requires_the_edit_ability(): void
    {
        $campaign = $this->campaign($this->phonebookWithContacts(1), [
            'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.campaigns.send-now', $campaign))
            ->assertForbidden();

        $this->assertTrue($campaign->fresh()->scheduled_at->isFuture());
    }

    public function test_send_now_forces_a_scheduled_campaign_to_go_immediately(): void
    {
        $campaign = $this->campaign($this->phonebookWithContacts(1), [
            'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.campaigns.send-now', $campaign))
            ->assertRedirect(route('whatsapp-crm.campaigns.show', $campaign));

        $campaign->refresh();
        $this->assertSame('scheduled', $campaign->status);
        $this->assertTrue($campaign->scheduled_at->lessThanOrEqualTo(now()));
    }

    public function test_send_now_refuses_a_campaign_that_already_started(): void
    {
        $campaign = $this->campaign($this->phonebookWithContacts(1), ['status' => 'sending']);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.campaigns.send-now', $campaign))
            ->assertRedirect(route('whatsapp-crm.campaigns.show', $campaign));

        $this->assertSame('sending', $campaign->fresh()->status);
    }

    public function test_cancel_stops_an_unsent_campaign_without_touching_its_logs(): void
    {
        $phonebook = $this->phonebookWithContacts(1);
        $campaign = $this->campaign($phonebook, ['status' => 'sending']);
        $contact = $phonebook->contacts()->first();
        $sentLog = $campaign->logs()->create(['contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'sent']);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.campaigns.cancel', $campaign))
            ->assertRedirect(route('whatsapp-crm.campaigns.show', $campaign));

        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertSame('sent', $sentLog->fresh()->status);
    }

    public function test_cancel_refuses_a_completed_campaign(): void
    {
        $campaign = $this->campaign($this->phonebookWithContacts(1), ['status' => 'completed', 'completed_at' => now()]);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.campaigns.cancel', $campaign))
            ->assertRedirect(route('whatsapp-crm.campaigns.show', $campaign));

        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_destroy_requires_the_delete_ability(): void
    {
        $campaign = $this->campaign($this->phonebookWithContacts(1));

        $this->actingAs($this->employee(['view', 'edit']))
            ->delete(route('whatsapp-crm.campaigns.destroy', $campaign))
            ->assertForbidden();

        $this->assertNotNull($campaign->fresh());
    }

    public function test_destroy_removes_a_campaign_that_never_sent_anything(): void
    {
        $campaign = $this->campaign($this->phonebookWithContacts(1), ['status' => 'draft']);

        $this->actingAs($this->employee(['view', 'delete']))
            ->delete(route('whatsapp-crm.campaigns.destroy', $campaign))
            ->assertRedirect(route('whatsapp-crm.campaigns.index'));

        $this->assertDatabaseMissing('whatsapp_campaigns', ['id' => $campaign->id]);
    }

    public function test_destroy_refuses_a_campaign_that_has_already_started_sending(): void
    {
        $campaign = $this->campaign($this->phonebookWithContacts(1), ['status' => 'sending']);

        $this->actingAs($this->employee(['view', 'delete']))
            ->delete(route('whatsapp-crm.campaigns.destroy', $campaign))
            ->assertRedirect(route('whatsapp-crm.campaigns.index'));

        $this->assertDatabaseHas('whatsapp_campaigns', ['id' => $campaign->id]);
    }

    // -- whatsapp:dispatch-campaigns ------------------------------------------

    public function test_the_command_promotes_a_due_campaign_to_sending_and_queues_its_pending_logs(): void
    {
        Bus::fake();

        $phonebook = $this->phonebookWithContacts(2);
        $campaign = $this->campaign($phonebook, ['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
        foreach ($phonebook->contacts as $contact) {
            $campaign->logs()->create(['contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending']);
        }

        Artisan::call('whatsapp:dispatch-campaigns');

        $campaign->refresh();
        $this->assertSame('sending', $campaign->status);
        $this->assertNotNull($campaign->started_at);

        Bus::assertDispatchedTimes(SendWhatsappCampaignMessage::class, 2);

        foreach ($campaign->logs as $log) {
            $this->assertNotNull($log->dispatched_at);
            Bus::assertDispatched(fn (SendWhatsappCampaignMessage $job) => $job->campaignLogId === $log->id);
        }
    }

    public function test_the_command_leaves_a_campaign_scheduled_for_the_future_alone(): void
    {
        Bus::fake();

        $campaign = $this->campaign($this->phonebookWithContacts(1), [
            'status' => 'scheduled', 'scheduled_at' => now()->addHour(),
        ]);

        Artisan::call('whatsapp:dispatch-campaigns');

        $this->assertSame('scheduled', $campaign->fresh()->status);
        Bus::assertNotDispatched(SendWhatsappCampaignMessage::class);
    }

    /**
     * A log already stamped dispatched_at on a previous tick must not be
     * queued a second time just because its job has not run (and so is
     * still `pending`) yet -- see the migration that added the column for
     * why this matters.
     */
    public function test_the_command_does_not_redispatch_an_already_dispatched_log(): void
    {
        Bus::fake();

        $phonebook = $this->phonebookWithContacts(1);
        $campaign = $this->campaign($phonebook, ['status' => 'sending']);
        $contact = $phonebook->contacts()->first();
        $campaign->logs()->create([
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'pending',
            'dispatched_at' => now()->subSeconds(30),
        ]);

        Artisan::call('whatsapp:dispatch-campaigns');

        Bus::assertNotDispatched(SendWhatsappCampaignMessage::class);
    }

    /**
     * The scenario the FK-style whereNull/pluck-then-update version of this
     * command was vulnerable to: a run that takes long enough for a second
     * tick to start before the first has stamped every row's dispatched_at.
     * Simulated here by calling the command twice back to back with
     * Bus::fake() in play -- nothing dispatched by the first call has
     * actually run, so every log is still `pending` when the second call's
     * own claim step looks at the table, exactly as it would be mid-way
     * through an overlapping real run. The per-row atomic claim in
     * claimAndDispatch() must still land each log's job exactly once.
     */
    public function test_calling_the_command_twice_in_a_row_does_not_double_dispatch_the_same_logs(): void
    {
        Bus::fake();

        $phonebook = $this->phonebookWithContacts(3);
        $campaign = $this->campaign($phonebook, ['status' => 'sending']);
        foreach ($phonebook->contacts as $contact) {
            $campaign->logs()->create(['contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending']);
        }

        Artisan::call('whatsapp:dispatch-campaigns');
        Artisan::call('whatsapp:dispatch-campaigns');

        Bus::assertDispatchedTimes(SendWhatsappCampaignMessage::class, 3);
        $this->assertSame(3, $campaign->logs()->whereNotNull('dispatched_at')->count());
    }

    public function test_a_sending_campaign_with_zero_pending_logs_left_flips_to_completed(): void
    {
        Bus::fake();

        $phonebook = $this->phonebookWithContacts(2);
        $campaign = $this->campaign($phonebook, ['status' => 'sending']);
        foreach ($phonebook->contacts as $i => $contact) {
            $campaign->logs()->create([
                'contact_id' => $contact->id,
                'phone' => $contact->phone,
                'status' => $i === 0 ? 'sent' : 'failed',
                'dispatched_at' => now(),
            ]);
        }

        Artisan::call('whatsapp:dispatch-campaigns');

        $campaign->refresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertNotNull($campaign->completed_at);
    }

    public function test_a_sending_campaign_with_pending_logs_remaining_is_not_completed(): void
    {
        Bus::fake();

        $phonebook = $this->phonebookWithContacts(1);
        $campaign = $this->campaign($phonebook, ['status' => 'sending']);
        $contact = $phonebook->contacts()->first();
        $campaign->logs()->create(['contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending']);

        Artisan::call('whatsapp:dispatch-campaigns');

        $this->assertSame('sending', $campaign->fresh()->status);
    }
}
