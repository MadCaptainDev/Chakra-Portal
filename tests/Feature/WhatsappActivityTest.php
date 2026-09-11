<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignLog;
use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use App\Models\WhatsappWebhookEvent;
use App\Support\WhatsappActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsappActivityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function outgoing(string $template, string $wamid): WhatsappWebhookEvent
    {
        return WhatsappWebhookEvent::recordOutgoing(
            to: '919876543210',
            wamid: $wamid,
            messageType: 'template',
            summary: 'Test message',
            payload: ['to' => '919876543210', 'type' => 'template', 'template' => ['name' => $template]],
        );
    }

    public function test_a_guest_and_an_ungranted_employee_are_refused(): void
    {
        $this->get(route('whatsapp-crm.activity.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('whatsapp-crm.activity.index'))
            ->assertForbidden();
    }

    public function test_a_known_template_is_labelled_in_plain_language(): void
    {
        $this->outgoing(Shoot::WHATSAPP_TEMPLATE_REMINDER, 'wamid.1');

        $response = $this->actingAs($this->admin())->get(route('whatsapp-crm.activity.index'));

        $response->assertOk();
        $response->assertSee('Shoot reminder');
    }

    public function test_a_campaign_send_is_labelled_with_the_campaigns_own_name(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'All contacts']);
        $campaign = WhatsappCampaign::create([
            'name' => 'Diwali Offer Blast',
            'meta_template_name' => 'diwali_offer',
            'meta_template_language' => 'en_US',
            'phonebook_id' => $phonebook->id,
            'status' => 'sending',
        ]);
        $contact = WhatsappContact::create(['phone' => '919876543210', 'name' => 'Priya']);
        WhatsappCampaignLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'phone' => '919876543210',
            'status' => 'sent',
            'wamid' => 'wamid.campaign',
        ]);
        $this->outgoing('diwali_offer', 'wamid.campaign');

        $response = $this->actingAs($this->admin())->get(route('whatsapp-crm.activity.index'));

        $response->assertOk();
        $response->assertSee('Campaign');
        $response->assertSee('Diwali Offer Blast');
    }

    public function test_an_event_from_a_different_day_does_not_appear(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0));
        $this->outgoing(Shoot::WHATSAPP_TEMPLATE_REMINDER, 'wamid.yesterday');

        Carbon::setTestNow(Carbon::create(2026, 9, 16, 10, 0));

        $response = $this->actingAs($this->admin())->get(route('whatsapp-crm.activity.index'));

        $response->assertOk();
        $response->assertDontSee('Shoot reminder');

        Carbon::setTestNow();
    }

    public function test_a_specific_day_can_be_requested_by_query_string(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0));
        $this->outgoing(Invoice::WHATSAPP_TEMPLATE, 'wamid.old');
        Carbon::setTestNow();

        $response = $this->actingAs($this->admin())
            ->get(route('whatsapp-crm.activity.index', ['day' => '2026-09-15']));

        $response->assertOk();
        $response->assertSee('Invoice ready');
    }

    public function test_daily_totals_span_the_requested_number_of_days(): void
    {
        $client = Client::factory()->create();
        $this->outgoing(Client::WHATSAPP_TEMPLATE_DEPLETION, 'wamid.today');

        $totals = WhatsappActivity::dailyTotals(7);

        $this->assertCount(7, $totals);
        $this->assertSame(1, $totals->first()['total']);
        $this->assertTrue($totals->first()['date']->isToday());
    }
}
