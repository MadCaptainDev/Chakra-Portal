<?php

namespace Tests\Feature;

use App\Models\WhatsappSetting;
use App\Services\WhatsappTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappTemplateListTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): WhatsappSetting
    {
        $settings = WhatsappSetting::current();

        $settings->update([
            'access_token' => 'EAAG-test-token',
            'business_account_id' => '102290129340398',
        ]);

        return $settings->fresh();
    }

    /** @param array<int, array<string, mixed>> $templates */
    private function fakeTemplates(array $templates): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => $templates]),
        ]);
    }

    /** @return array<string, string> */
    private function queryFrom(Request $request): array
    {
        $query = parse_url($request->url(), PHP_URL_QUERY) ?? '';
        parse_str($query, $parsed);

        return $parsed;
    }

    public function test_it_calls_the_exact_endpoint_meta_expects(): void
    {
        $this->configured();
        $this->fakeTemplates([
            ['name' => 'hello_world', 'status' => 'APPROVED', 'language' => 'en_US'],
        ]);

        WhatsappTemplateService::make()->list();

        Http::assertSent(function (Request $request) {
            $path = strtok($request->url(), '?');

            return $request->method() === 'GET'
                && $path === 'https://graph.facebook.com/v22.0/102290129340398/message_templates'
                && $this->queryFrom($request) === [
                    'fields' => 'name,status,language,category,components',
                    'limit' => '100',
                ]
                && $request->hasHeader('Authorization', 'Bearer EAAG-test-token');
        });
    }

    public function test_only_approved_templates_are_returned_by_default(): void
    {
        $this->configured();
        $this->fakeTemplates([
            ['name' => 'hello_world', 'status' => 'APPROVED'],
            ['name' => 'draft_one', 'status' => 'PENDING'],
        ]);

        $templates = WhatsappTemplateService::make()->list();

        $this->assertCount(1, $templates);
        $this->assertSame('hello_world', $templates[0]['name']);
    }

    public function test_approved_only_can_be_turned_off(): void
    {
        $this->configured();
        $this->fakeTemplates([
            ['name' => 'hello_world', 'status' => 'APPROVED'],
            ['name' => 'draft_one', 'status' => 'PENDING'],
        ]);

        $templates = WhatsappTemplateService::make()->list(approvedOnly: false);

        $this->assertCount(2, $templates);
    }

    /**
     * The whole point of caching: a screen that lists templates twice in one
     * page load, or a user who reopens the campaign builder a minute later,
     * must not cost a second Graph call.
     */
    public function test_a_second_call_within_five_minutes_is_served_from_the_cache(): void
    {
        $this->configured();
        $this->fakeTemplates([['name' => 'hello_world', 'status' => 'APPROVED']]);

        WhatsappTemplateService::make()->list();
        WhatsappTemplateService::make()->list();

        Http::assertSentCount(1);
    }

    public function test_refresh_busts_the_cache_and_calls_meta_again(): void
    {
        $this->configured();
        $this->fakeTemplates([['name' => 'hello_world', 'status' => 'APPROVED']]);

        WhatsappTemplateService::make()->list();
        WhatsappTemplateService::make()->refresh();

        Http::assertSentCount(2);
    }

    /**
     * A screen that lists templates before WhatsApp is set up must render an
     * empty state, not a 500 -- this is what makes that possible.
     */
    public function test_an_unconfigured_account_returns_an_empty_array_without_an_exception(): void
    {
        Http::fake();

        $templates = WhatsappTemplateService::make()->list();

        $this->assertSame([], $templates);
        $this->assertNull(WhatsappSetting::current()->business_account_id);
        Http::assertNothingSent();
    }
}
