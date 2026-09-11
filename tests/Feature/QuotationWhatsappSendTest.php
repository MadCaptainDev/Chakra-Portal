<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Send via WhatsApp" on a quotation -- same shape as
 * InvoiceWhatsappSendTest, one level down (Quotation::WHATSAPP_TEMPLATE,
 * q/{token} instead of i/{token}). Every attempt, sent or failed, also
 * writes a WhatsappSendLog row -- see DocumentWhatsappNotifier.
 */
class QuotationWhatsappSendTest extends TestCase
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

    public function test_sending_posts_the_template_and_logs_it(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        $quotation = Quotation::factory()->create(['total' => 100000, 'quotation_number' => 'QT-0001']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('quotations.send-whatsapp', $quotation), [
            'phone' => '9999888877',
        ]);

        $quotation->refresh();
        $response->assertRedirect(route('quotations.show', $quotation));
        $response->assertSessionHas('status', fn (string $status) => str_contains($status, '9999888877'));
        $this->assertNotNull($quotation->whatsapp_sent_at);
        $this->assertNotNull($quotation->public_token);

        $log = WhatsappSendLog::first();
        $this->assertSame(WhatsappSendLog::STATUS_SENT, $log->status);
        $this->assertSame('9999888877', $log->phone);
        $this->assertSame(Quotation::WHATSAPP_TEMPLATE, $log->template);
        $this->assertSame($user->id, $log->sent_by);
        $this->assertSame($quotation->id, $log->loggable_id);

        Http::assertSent(function (Request $request) use ($quotation) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '123456789/messages')
                && $body['to'] === '919999888877'
                && $body['template']['name'] === Quotation::WHATSAPP_TEMPLATE
                && $body['template']['components'][1]['type'] === 'button'
                && str_contains($body['template']['components'][1]['parameters'][0]['text'], $quotation->public_token);
        });
    }

    public function test_a_blank_phone_is_rejected_before_meta_is_ever_called(): void
    {
        $this->configured();
        Http::fake();

        $quotation = Quotation::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('quotations.send-whatsapp', $quotation), ['phone' => '']);

        $response->assertSessionHasErrors('phone');
        Http::assertNothingSent();
        $this->assertSame(0, WhatsappSendLog::count());
    }

    public function test_metas_own_failure_reason_is_surfaced_and_logged_as_failed(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Template not approved yet.'],
        ], 400)]);

        $quotation = Quotation::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('quotations.send-whatsapp', $quotation), ['phone' => '9876543210']);

        $response->assertSessionHas('error', 'Template not approved yet.');
        $this->assertNull($quotation->refresh()->whatsapp_sent_at);

        $log = WhatsappSendLog::first();
        $this->assertSame(WhatsappSendLog::STATUS_FAILED, $log->status);
        $this->assertSame('Template not approved yet.', $log->error);
    }

    public function test_the_public_link_serves_the_pdf_without_authentication(): void
    {
        $quotation = Quotation::factory()->create();
        $token = $quotation->ensurePublicToken();

        $response = $this->get(route('quotations.public-pdf', $token));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_unknown_token_is_a_404_not_a_500(): void
    {
        $this->get(route('quotations.public-pdf', 'does-not-exist'))->assertNotFound();
    }
}
