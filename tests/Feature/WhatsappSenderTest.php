<?php

namespace Tests\Feature;

use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WhatsappSenderTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): WhatsappSetting
    {
        $settings = WhatsappSetting::current();

        $settings->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);

        return $settings->fresh();
    }

    /** @param array<string, mixed> $body */
    private function fakeMeta(array $body = ['messages' => [['id' => 'wamid.OUT1']]], int $status = 200): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);
    }

    public function test_a_template_is_sent_in_the_shape_meta_expects(): void
    {
        $this->configured();
        $this->fakeMeta();

        $result = WhatsappSender::make()->sendTemplate('7094126823', 'hello_world');

        $this->assertSame('wamid.OUT1', $result['wamid']);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://graph.facebook.com/v22.0/556677889900/messages'
                && $request->hasHeader('Authorization', 'Bearer EAAG-test-token')
                && $body['messaging_product'] === 'whatsapp'
                // The bare 10-digit number has picked up its country code.
                && $body['to'] === '917094126823'
                && $body['type'] === 'template'
                && $body['template']['name'] === 'hello_world'
                && $body['template']['language']['code'] === 'en_US';
        });
    }

    public function test_template_parameters_are_positional(): void
    {
        $this->configured();
        $this->fakeMeta();

        WhatsappSender::make()->sendTemplate('917094126823', 'shoot_reminder', 'en', ['Friday', '4pm']);

        Http::assertSent(function (Request $request) {
            $parameters = $request->data()['template']['components'][0]['parameters'];

            return $parameters === [
                ['type' => 'text', 'text' => 'Friday'],
                ['type' => 'text', 'text' => '4pm'],
            ];
        });
    }

    public function test_free_text_is_sent_as_a_text_message(): void
    {
        $this->configured();
        $this->fakeMeta();

        WhatsappSender::make()->sendText('917094126823', 'Running ten minutes late.');

        Http::assertSent(fn (Request $request) => $request->data()['type'] === 'text'
            && $request->data()['text']['body'] === 'Running ten minutes late.');
    }

    public function test_a_sent_message_is_filed_next_to_the_statuses_it_will_get(): void
    {
        $this->configured();
        $this->fakeMeta();

        WhatsappSender::make()->sendTemplate('7094126823', 'hello_world');

        $event = WhatsappWebhookEvent::sole();

        $this->assertSame(WhatsappWebhookEvent::TYPE_OUTGOING, $event->type);
        $this->assertSame('wamid.OUT1', $event->external_id);
        $this->assertSame('917094126823', $event->wa_id);
        $this->assertSame('[template: hello_world]', $event->summary);
    }

    public function test_metas_error_is_reported_verbatim(): void
    {
        $this->configured();
        $this->fakeMeta([
            'error' => [
                'message' => '(#131030) Recipient phone number not in allowed list',
                'error_data' => ['details' => 'Add the number in API Setup and verify it.'],
            ],
        ], status: 400);

        // The specific reason is the whole value of the failure -- flattened to
        // "sending failed" it would cost an afternoon.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipient phone number not in allowed list — Add the number in API Setup and verify it.');

        WhatsappSender::make()->sendTemplate('917094126823', 'hello_world');
    }

    public function test_a_failed_send_is_not_recorded_as_sent(): void
    {
        $this->configured();
        $this->fakeMeta(['error' => ['message' => 'Invalid OAuth access token']], status: 401);

        try {
            WhatsappSender::make()->sendText('917094126823', 'hello');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_sending_without_configuration_refuses_before_calling_meta(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WhatsApp sending is not configured');

        WhatsappSender::make()->sendText('917094126823', 'hello');

        Http::assertNothingSent();
    }

    public function test_numbers_are_normalised_the_way_meta_wants_them(): void
    {
        $this->assertSame('917094126823', WhatsappSender::normalise('7094126823'));
        $this->assertSame('917094126823', WhatsappSender::normalise('+91 70941 26823'));
        $this->assertSame('917094126823', WhatsappSender::normalise('917094126823'));
        // Already carries a country code that is not India -- left alone.
        $this->assertSame('14155552671', WhatsappSender::normalise('+1 415 555 2671'));
    }
}
