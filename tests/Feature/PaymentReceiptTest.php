<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(?string $phone = '9876543210', int $status = 200): Invoice
    {
        WhatsappSetting::current()->update(['access_token' => 'EAAG-test-token', 'phone_number_id' => '556677889900']);
        Http::fake(['graph.facebook.com/*' => $status === 200
            ? Http::response(['messages' => [['id' => 'wamid.TEST']]])
            : Http::response(['error' => ['message' => 'Template not approved']], $status)]);
        $client = Client::factory()->create(['name' => 'Janet', 'phone' => $phone]);

        return Invoice::factory()->create(['client_id' => $client->id, 'invoice_number' => 'CP-0042', 'total' => 10000]);
    }

    private function pay(Invoice $invoice, array $extra = [])
    {
        return $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('payments.store', $invoice), ['amount' => 4000, 'paid_on' => today()->toDateString(), 'notify_client' => '1', ...$extra]);
    }

    public function test_recording_a_payment_thanks_the_client_with_the_template(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice)->assertRedirect()->assertSessionHas('status', 'Payment recorded, and a thank-you sent to 9876543210 on WhatsApp.');

        Http::assertSent(fn ($r) => $r->data()['type'] === 'template'
            && $r->data()['template']['name'] === Invoice::WHATSAPP_TEMPLATE_PAYMENT_RECEIVED
            && array_column($r->data()['template']['components'][0]['parameters'], 'text') === ['Janet', '4,000.00', 'CP-0042', '6,000.00']);
        $this->assertSame(1, $invoice->whatsappLogs()->count());
    }

    public function test_unticking_the_box_sends_nothing(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice, ['notify_client' => '0'])->assertSessionHas('status', 'Payment recorded.');

        Http::assertNothingSent();
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_no_phone_means_the_payment_is_kept_and_the_reason_shown(): void
    {
        $invoice = $this->invoice(null);

        $this->pay($invoice)->assertSessionHas('error', 'The WhatsApp receipt was not sent: the client has no phone number on file');

        $this->assertSame(1, $invoice->payments()->count());
        Http::assertNothingSent();
    }

    public function test_a_whatsapp_failure_never_loses_the_payment(): void
    {
        $invoice = $this->invoice('9876543210', 400);

        $this->pay($invoice)->assertSessionHas('error');

        $this->assertSame(1, $invoice->payments()->count());
        $this->assertEquals(6000, $invoice->fresh()->balanceDue());
    }
}
