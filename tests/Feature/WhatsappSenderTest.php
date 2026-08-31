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

    // -- sendInteractiveList() ---------------------------------------------

    public function test_a_list_message_is_sent_in_the_shape_meta_expects(): void
    {
        $this->configured();
        $this->fakeMeta();

        WhatsappSender::make()->sendInteractiveList(
            '917094126823',
            'Pick one',
            [
                ['id' => '1', 'title' => 'Invoices', 'description' => 'Your recent bills'],
                ['id' => '2', 'title' => 'Report'],
            ],
            buttonLabel: 'Select Option',
            header: 'Chakra Groups',
            footer: 'Type menu anytime',
        );

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $data['type'] === 'interactive'
                && $data['interactive']['type'] === 'list'
                && $data['interactive']['header'] === ['type' => 'text', 'text' => 'Chakra Groups']
                && $data['interactive']['body'] === ['text' => 'Pick one']
                && $data['interactive']['footer'] === ['text' => 'Type menu anytime']
                && $data['interactive']['action']['button'] === 'Select Option'
                && $data['interactive']['action']['sections'] === [[
                    'rows' => [
                        ['id' => '1', 'title' => 'Invoices', 'description' => 'Your recent bills'],
                        ['id' => '2', 'title' => 'Report'],
                    ],
                ]];
        });
    }

    public function test_a_list_omits_header_footer_and_description_keys_when_blank(): void
    {
        $this->configured();
        $this->fakeMeta();

        WhatsappSender::make()->sendInteractiveList('917094126823', 'Pick one', [
            ['id' => '1', 'title' => 'Invoices'],
        ]);

        Http::assertSent(function (Request $request) {
            $interactive = $request->data()['interactive'];

            return ! array_key_exists('header', $interactive)
                && ! array_key_exists('footer', $interactive)
                && ! array_key_exists('description', $interactive['action']['sections'][0]['rows'][0])
                && $interactive['action']['button'] === 'Select Option';
        });
    }

    /**
     * Body/title/description can carry {{variable}} interpolation resolved
     * by the caller before this method ever sees them -- a client's own
     * long name pushing a body past Meta's 1024-char limit is a per-send
     * accident, not a configuration mistake, so that client still gets a
     * working (if clipped) menu rather than nothing. Hard length checks
     * belong at save time instead (DrawflowGraphTranslator::castListRows()).
     */
    public function test_a_list_body_longer_than_the_limit_is_clamped_rather_than_refused(): void
    {
        $this->configured();
        $this->fakeMeta();

        $longBody = str_repeat('a', 1200);

        WhatsappSender::make()->sendInteractiveList('917094126823', $longBody, [['id' => '1', 'title' => 'Go']]);

        Http::assertSent(fn (Request $request) => mb_strlen($request->data()['interactive']['body']['text']) === 1024);
    }

    public function test_a_list_with_more_than_ten_rows_is_refused_before_calling_meta(): void
    {
        $this->configured();
        Http::fake();

        $rows = collect(range(1, 11))->map(fn (int $i) => ['id' => (string) $i, 'title' => "Option {$i}"])->all();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most 10 options');

        WhatsappSender::make()->sendInteractiveList('917094126823', 'Pick one', $rows);

        Http::assertNothingSent();
    }

    public function test_a_list_with_no_rows_is_refused_before_calling_meta(): void
    {
        $this->configured();
        Http::fake();

        $this->expectException(RuntimeException::class);

        WhatsappSender::make()->sendInteractiveList('917094126823', 'Pick one', []);

        Http::assertNothingSent();
    }

    /**
     * The staff-facing inbox thread (whatsapp-crm/inbox/show.blade.php)
     * renders only message.summary -- without the option titles folded in
     * there, an outgoing list would show as a bare prompt with no visible
     * menu, the one thing that actually matters about it.
     */
    public function test_a_sent_list_is_filed_with_its_options_in_the_summary(): void
    {
        $this->configured();
        $this->fakeMeta();

        WhatsappSender::make()->sendInteractiveList('917094126823', 'Pick one', [
            ['id' => '1', 'title' => 'Invoices'],
            ['id' => '2', 'title' => 'Report'],
        ]);

        $event = WhatsappWebhookEvent::sole();

        $this->assertSame(WhatsappWebhookEvent::TYPE_OUTGOING, $event->type);
        $this->assertStringContainsString('Pick one', $event->summary);
        $this->assertStringContainsString('Invoices, Report', $event->summary);
    }
}
