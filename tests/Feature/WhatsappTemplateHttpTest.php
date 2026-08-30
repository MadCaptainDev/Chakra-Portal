<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The read-only Templates screen: lists what WhatsappTemplateService returns,
 * shows a "connect first" empty state when WhatsappSetting::canSend() is
 * false, and refreshes the cache through its own endpoint.
 */
class WhatsappTemplateHttpTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['whatsapp-crm' => $abilities]);

        return $user->refresh();
    }

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

    /** @param array<int, array<string, mixed>> $templates */
    private function fakeTemplates(array $templates): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => $templates]),
        ]);
    }

    public function test_an_ungranted_employee_is_refused_the_index(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.templates.index'))->assertForbidden();
    }

    public function test_the_connect_first_empty_state_renders_when_unconfigured(): void
    {
        Http::fake();

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.templates.index'))
            ->assertOk()
            ->assertSee('Connect WhatsApp first', false)
            ->assertSee(route('whatsapp.edit'), false);

        Http::assertNothingSent();
    }

    public function test_a_configured_account_lists_its_approved_templates(): void
    {
        $this->configured();
        $this->fakeTemplates([
            ['name' => 'hello_world', 'status' => 'APPROVED', 'language' => 'en_US', 'category' => 'MARKETING'],
            ['name' => 'draft_one', 'status' => 'PENDING', 'language' => 'en_US', 'category' => 'UTILITY'],
        ]);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.templates.index'))
            ->assertOk()
            ->assertSee('hello_world')
            ->assertDontSee('draft_one');
    }

    /**
     * Every template list() ever returns is pre-filtered to APPROVED (the
     * controller never passes approvedOnly: false), so this is the one
     * status badge that actually renders on this screen -- pin its styled
     * class and Title Case label so a future badge.blade.php change that
     * drops the 'approved' map entry (falling back to the raw, all-caps
     * "APPROVED" label) fails a test instead of shipping unnoticed.
     */
    public function test_the_status_badge_renders_styled_rather_than_the_raw_uppercase_fallback(): void
    {
        $this->configured();
        $this->fakeTemplates([
            ['name' => 'hello_world', 'status' => 'APPROVED', 'language' => 'en_US', 'category' => 'MARKETING'],
        ]);

        $response = $this->actingAs($this->employee())->get(route('whatsapp-crm.templates.index'));

        $response->assertOk();
        $response->assertSee('bg-emerald-400/15 text-emerald-200', false);
        $response->assertSee('Approved', false);
        $response->assertDontSee('APPROVED', false);
    }

    public function test_refresh_busts_the_cache_and_calls_meta_again(): void
    {
        $this->configured();
        $this->fakeTemplates([['name' => 'hello_world', 'status' => 'APPROVED', 'language' => 'en_US', 'category' => 'MARKETING']]);

        $this->actingAs($this->employee())->get(route('whatsapp-crm.templates.index'));
        Http::assertSentCount(1);

        $response = $this->actingAs($this->employee())->post(route('whatsapp-crm.templates.refresh'));
        $response->assertRedirect(route('whatsapp-crm.templates.index'));

        Http::assertSentCount(2);
    }

    public function test_an_employee_without_create_is_refused_the_form_and_the_submit(): void
    {
        $employee = $this->employee(['view']);

        $this->actingAs($employee)->get(route('whatsapp-crm.templates.create'))->assertForbidden();
        $this->actingAs($employee)->post(route('whatsapp-crm.templates.store'), [
            'name' => 'order_confirmation',
            'category' => 'MARKETING',
            'language' => 'en_US',
            'body' => 'Hi {{1}}.',
        ])->assertForbidden();
    }

    public function test_a_valid_submission_is_posted_to_meta_and_redirects_with_a_status_message(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123', 'status' => 'PENDING'])]);

        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.templates.store'), [
                'name' => 'order_confirmation',
                'category' => 'MARKETING',
                'language' => 'en_US',
                'body' => 'Hi {{1}}, your order {{2}} has shipped.',
            ]);

        $response->assertRedirect(route('whatsapp-crm.templates.index'));
        $response->assertSessionHas('status', fn (string $status) => str_contains($status, 'submitted to Meta'));
        Http::assertSentCount(1);
    }

    public function test_an_invalid_name_is_rejected_before_meta_is_ever_called(): void
    {
        Http::fake();

        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.templates.store'), [
                'name' => 'Order Confirmation!',
                'category' => 'MARKETING',
                'language' => 'en_US',
                'body' => 'Hi {{1}}.',
            ]);

        $response->assertSessionHasErrors('name');
        Http::assertNothingSent();
    }

    public function test_metas_own_rejection_reason_is_surfaced_on_the_form(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'A template with this name already exists.'],
        ], 400)]);

        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.templates.store'), [
                'name' => 'order_confirmation',
                'category' => 'MARKETING',
                'language' => 'en_US',
                'body' => 'Hi {{1}}.',
            ]);

        $response->assertSessionHasErrors(['name' => 'A template with this name already exists.']);
    }
}
