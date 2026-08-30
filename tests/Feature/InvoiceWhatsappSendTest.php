<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Send via WhatsApp" on an invoice: gated to approved, recurring-generated
 * invoices whose client has a phone number, sends the `invoice_ready`
 * template with a link to the no-login PDF, and records when it went out.
 */
class InvoiceWhatsappSendTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): WhatsappSetting
    {
        $settings = WhatsappSetting::current();

        $settings->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '123456789',
            'business_account_id' => '102290129340398',
        ]);

        return $settings->fresh();
    }

    private function recurringInvoice(array $attributes = []): Invoice
    {
        $client = Client::factory()->create(['phone' => '9876543210']);
        $recurring = RecurringInvoice::factory()->create(['client_id' => $client->id]);

        return Invoice::factory()->create(array_merge([
            'client_id' => $client->id,
            'recurring_invoice_id' => $recurring->id,
        ], $attributes));
    }

    public function test_a_manually_created_invoice_is_not_sendable_via_whatsapp(): void
    {
        $invoice = Invoice::factory()->create(); // no recurring_invoice_id

        $this->assertFalse($invoice->isSendableViaWhatsapp());
    }

    public function test_a_recurring_invoice_without_a_client_phone_is_not_sendable(): void
    {
        $client = Client::factory()->create(['phone' => null]);
        $recurring = RecurringInvoice::factory()->create(['client_id' => $client->id]);
        $invoice = Invoice::factory()->create([
            'client_id' => $client->id,
            'recurring_invoice_id' => $recurring->id,
        ]);

        $this->assertFalse($invoice->isSendableViaWhatsapp());
    }

    public function test_a_pending_approval_recurring_invoice_is_not_sendable_yet(): void
    {
        $invoice = $this->recurringInvoice([
            'status' => Invoice::STATUS_PENDING_APPROVAL,
            'invoice_number' => null,
        ]);

        $this->assertFalse($invoice->isSendableViaWhatsapp());
    }

    public function test_an_approved_recurring_invoice_with_a_client_phone_is_sendable(): void
    {
        $invoice = $this->recurringInvoice();

        $this->assertTrue($invoice->isSendableViaWhatsapp());
    }

    public function test_sending_posts_the_template_with_a_link_and_records_the_timestamp(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        $invoice = $this->recurringInvoice(['total' => 12500]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice));

        $invoice->refresh();
        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('status');
        $this->assertNotNull($invoice->whatsapp_sent_at);
        $this->assertNotNull($invoice->public_token);

        Http::assertSent(function (Request $request) use ($invoice) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '123456789/messages')
                && $body['template']['name'] === 'invoice_ready'
                && $body['template']['components'][0]['parameters'][2]['text'] === '12,500.00'
                && $body['template']['components'][1]['type'] === 'button'
                && str_contains($body['template']['components'][1]['parameters'][0]['text'], $invoice->public_token);
        });
    }

    public function test_the_public_link_serves_the_pdf_without_authentication(): void
    {
        $invoice = $this->recurringInvoice();
        $token = $invoice->ensurePublicToken();

        $response = $this->get(route('invoices.public-pdf', $token));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_unknown_token_is_a_404_not_a_500(): void
    {
        $this->get(route('invoices.public-pdf', 'does-not-exist'))->assertNotFound();
    }

    public function test_a_second_send_reuses_the_same_token(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        $invoice = $this->recurringInvoice();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice));
        $firstToken = $invoice->refresh()->public_token;

        $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice));
        $secondToken = $invoice->refresh()->public_token;

        $this->assertSame($firstToken, $secondToken);
    }

    public function test_sending_a_non_sendable_invoice_is_refused_with_an_error(): void
    {
        $invoice = Invoice::factory()->create(); // not recurring
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice));

        $response->assertSessionHas('error');
        $this->assertNull($invoice->refresh()->whatsapp_sent_at);
    }

    public function test_metas_own_failure_reason_is_surfaced_and_nothing_is_recorded(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Template not approved yet.'],
        ], 400)]);

        $invoice = $this->recurringInvoice();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice));

        $response->assertSessionHas('error', 'Template not approved yet.');
        $this->assertNull($invoice->refresh()->whatsapp_sent_at);
    }
}
