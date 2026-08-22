<?php

namespace Tests\Feature;

use App\Models\CompetitorSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin Setup screen for the three competitor-analysis API keys.
 * Mirrors PushSettingTest's coverage of the same shape.
 */
class CompetitorSettingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $this->get(route('competitor-settings.edit'))->assertRedirect(route('login'));
        $this->put(route('competitor-settings.update'), [])->assertRedirect(route('login'));
    }

    public function test_an_employee_cannot_reach_the_settings_screen(): void
    {
        $this->actingAs($this->employee())->get(route('competitor-settings.edit'))->assertForbidden();
    }

    public function test_the_screen_shows_not_set_when_empty(): void
    {
        $this->actingAs($this->admin())
            ->get(route('competitor-settings.edit'))
            ->assertOk()
            ->assertSeeText('Not set');
    }

    public function test_saving_all_three_keys_persists_them_as_plaintext_on_the_model(): void
    {
        $this->actingAs($this->admin())->put(route('competitor-settings.update'), [
            'apify_token' => 'apify-token',
            'gemini_api_key' => 'gemini-key',
            'anthropic_api_key' => 'anthropic-key',
            'gemini_model' => 'gemini-2.5-flash',
        ])->assertRedirect(route('competitor-settings.edit'));

        $settings = CompetitorSetting::current();
        $this->assertSame('apify-token', $settings->apify_token);
        $this->assertSame('gemini-key', $settings->gemini_api_key);
        $this->assertSame('anthropic-key', $settings->anthropic_api_key);
    }

    public function test_the_raw_database_columns_are_not_plaintext(): void
    {
        $this->actingAs($this->admin())->put(route('competitor-settings.update'), [
            'apify_token' => 'apify-token',
            'gemini_api_key' => 'gemini-key',
            'anthropic_api_key' => 'anthropic-key',
            'gemini_model' => 'gemini-2.5-flash',
        ]);

        $raw = DB::table('competitor_settings')->first();
        $this->assertStringNotContainsString('apify-token', $raw->apify_token);
        $this->assertStringNotContainsString('gemini-key', $raw->gemini_api_key);
        $this->assertStringNotContainsString('anthropic-key', $raw->anthropic_api_key);
    }

    public function test_saving_blank_keeps_every_existing_key(): void
    {
        CompetitorSetting::current()->update([
            'apify_token' => 'apify-token', 'gemini_api_key' => 'gemini-key', 'anthropic_api_key' => 'anthropic-key',
        ]);

        $this->actingAs($this->admin())->put(route('competitor-settings.update'), [
            'apify_token' => '', 'gemini_api_key' => '', 'anthropic_api_key' => '', 'gemini_model' => 'gemini-2.5-flash',
        ]);

        $settings = CompetitorSetting::current()->fresh();
        $this->assertSame('apify-token', $settings->apify_token);
        $this->assertSame('gemini-key', $settings->gemini_api_key);
        $this->assertSame('anthropic-key', $settings->anthropic_api_key);
    }

    public function test_the_rendered_screen_never_shows_a_saved_key_back(): void
    {
        CompetitorSetting::current()->update(['apify_token' => 'super-secret-token']);

        $this->actingAs($this->admin())
            ->get(route('competitor-settings.edit'))
            ->assertDontSee('super-secret-token');
    }

    public function test_the_test_button_reports_a_missing_key_without_calling_anthropic(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('competitor-settings.test'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Paste an Anthropic API key'));

        Http::assertNothingSent();
    }

    public function test_the_test_button_shows_anthropics_raw_error(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'bad-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $this->actingAs($this->admin())
            ->post(route('competitor-settings.test'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'invalid x-api-key'));
    }
}
