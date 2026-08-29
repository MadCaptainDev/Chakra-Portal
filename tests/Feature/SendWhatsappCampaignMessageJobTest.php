<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappCampaignMessage;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignLog;
use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One job, one recipient. See WhatsappCampaignTest for the command that
 * queues these; this file is about what handle() itself does once a job
 * actually runs -- the send, the merge-field resolution, and the two ways a
 * log row can end up (sent or failed) without the job ever throwing.
 */
class SendWhatsappCampaignMessageJobTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): void
    {
        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);
    }

    private function campaignWithLog(array $campaignOverrides = [], array $contactOverrides = []): WhatsappCampaignLog
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $contact = WhatsappContact::create($contactOverrides + [
            'phone' => '7094126823',
            'var1' => 'Ravi',
        ]);
        $phonebook->contacts()->attach($contact);

        $campaign = WhatsappCampaign::create($campaignOverrides + [
            'name' => 'August Reminder',
            'meta_template_name' => 'shoot_reminder',
            'meta_template_language' => 'en_US',
            'phonebook_id' => $phonebook->id,
            'status' => 'sending',
            'variable_mapping' => ['var1', 'Studio'],
        ]);

        return $campaign->logs()->create([
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);
    }

    public function test_a_successful_send_marks_the_log_sent_with_its_wamid_and_sent_at(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        $log = $this->campaignWithLog();

        (new SendWhatsappCampaignMessage($log->id))->handle();

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.OUT1', $log->wamid);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->error);
    }

    /**
     * variable_mapping's var1 resolves against the contact's own merge
     * field, while the second entry ("Studio") is not one of var1..var5 so
     * it is sent through literally -- one campaign, two different sources
     * for its two placeholders.
     */
    public function test_variable_mapping_resolves_merge_fields_and_passes_literals_through(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        $log = $this->campaignWithLog(contactOverrides: ['var1' => 'Ravi Kumar']);

        (new SendWhatsappCampaignMessage($log->id))->handle();

        Http::assertSent(function (Request $request) {
            $parameters = $request->data()['template']['components'][0]['parameters'];

            return $parameters === [
                ['type' => 'text', 'text' => 'Ravi Kumar'],
                ['type' => 'text', 'text' => 'Studio'],
            ];
        });
    }

    /**
     * Meta rejecting this one number must not throw out of the job -- the
     * queue has to keep going for every other recipient in the batch.
     */
    public function test_a_meta_failure_marks_the_log_failed_with_the_error_without_throwing(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Recipient phone number not in allowed list'],
        ], 400)]);

        $log = $this->campaignWithLog();

        (new SendWhatsappCampaignMessage($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Recipient phone number not in allowed list', $log->error);
        $this->assertNull($log->wamid);
        $this->assertNull($log->sent_at);
    }

    /**
     * Sending is not configured at all -- refused before ever calling Meta,
     * same as WhatsappSender itself, but here that refusal has to land on
     * the log row rather than bubble up as an exception.
     */
    public function test_an_unconfigured_account_fails_the_log_without_calling_meta(): void
    {
        Http::fake();

        $log = $this->campaignWithLog();

        (new SendWhatsappCampaignMessage($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('not configured', $log->error);

        Http::assertNothingSent();
    }

    public function test_a_log_that_no_longer_exists_is_a_silent_no_op(): void
    {
        Http::fake();

        (new SendWhatsappCampaignMessage(999999))->handle();

        Http::assertNothingSent();
    }
}
