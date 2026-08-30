<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Send via WhatsApp" on an invoice: any approved invoice, to any number
 * typed in (not just the client's own, not just recurring-generated), sends
 * Invoice::WHATSAPP_TEMPLATE with a link to the no-login PDF, and records
 * when it went out.
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

    public function test_a_pending_approval_invoice_is_not_sendable_yet(): void
    {
        $invoice = Invoice::factory()->pendingApproval()->create();

        $this->assertFalse($invoice->isSendableViaWhatsapp());
    }

    public function test_an_approved_invoice_is_sendable_even_if_manually_created(): void
    {
        $invoice = Invoice::factory()->create(); // no recurring_invoice_id

        $this->assertTrue($invoice->isSendableViaWhatsapp());
    }

    public function test_sending_posts_the_template_to_whatever_number_was_typed(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        $invoice = Invoice::factory()->create(['total' => 12500]);
        $user = User::factory()->create();

        // A number that is not the invoice's own client's phone at all --
        // the whole point of the widened feature is that any number typed
        // in is honoured, not only the one on file.
        $response = $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice), [
            'phone' => '9999888877',
        ]);

        $invoice->refresh();
        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('status', fn (string $status) => str_contains($status, '9999888877'));
        $this->assertNotNull($invoice->whatsapp_sent_at);
        $this->assertNotNull($invoice->public_token);

        Http::assertSent(function (Request $request) use ($invoice) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '123456789/messages')
                && $body['to'] === '919999888877'
                && $body['template']['name'] === Invoice::WHATSAPP_TEMPLATE
                && $body['template']['components'][0]['parameters'][2]['text'] === '12,500.00'
                && $body['template']['components'][1]['type'] === 'button'
                && str_contains($body['template']['components'][1]['parameters'][0]['text'], $invoice->public_token);
        });
    }

    public function test_a_blank_phone_is_rejected_before_meta_is_ever_called(): void
    {
        $this->configured();
        Http::fake();

        $invoice = Invoice::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice), ['phone' => '']);

        $response->assertSessionHasErrors('phone');
        Http::assertNothingSent();
    }

    public function test_the_public_link_serves_the_pdf_without_authentication(): void
    {
        $invoice = Invoice::factory()->create();
        $token = $invoice->ensurePublicToken();

        $response = $this->get(route('invoices.public-pdf', $token));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_unknown_token_is_a_404_not_a_500(): void
    {
        $this->get(route('invoices.public-pdf', 'does-not-exist'))->assertNotFound();
    }

    public function test_repeated_sends_reuse_the_same_token(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        $invoice = Invoice::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice), ['phone' => '9876543210']);
        $firstToken = $invoice->refresh()->public_token;

        // A second send, to a different number entirely -- the token is the
        // invoice's own PDF link, not tied to any one recipient.
        $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice), ['phone' => '9111222333']);
        $secondToken = $invoice->refresh()->public_token;

        $this->assertSame($firstToken, $secondToken);
    }

    public function test_sending_a_pending_approval_invoice_is_refused_with_an_error(): void
    {
        $invoice = Invoice::factory()->pendingApproval()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice), ['phone' => '9876543210']);

        $response->assertSessionHas('error');
        $this->assertNull($invoice->refresh()->whatsapp_sent_at);
    }

    public function test_metas_own_failure_reason_is_surfaced_and_nothing_is_recorded(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Template not approved yet.'],
        ], 400)]);

        $invoice = Invoice::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('invoices.send-whatsapp', $invoice), ['phone' => '9876543210']);

        $response->assertSessionHas('error', 'Template not approved yet.');
        $this->assertNull($invoice->refresh()->whatsapp_sent_at);
    }
}
