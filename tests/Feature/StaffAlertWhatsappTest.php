<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\Shoot;
use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The WhatsApp half of the two staff-facing alert commands that were
 * previously push-only: shoots:send-missing-content-alerts and
 * content:send-depletion-alerts. Both alerts themselves (recipients,
 * idempotency) already have their own coverage in Shoot/ContentForecast-
 * adjacent tests where it exists -- this covers only the new WhatsApp step.
 */
class StaffAlertWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private function configuredWhatsapp(): void
    {
        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);
    }

    private function admin(?string $phone = '9876543210'): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => $phone]);
    }

    // -- shoots:send-missing-content-alerts --------------------------------

    public function test_a_completed_shoot_missing_content_alerts_staff_with_a_phone_over_whatsapp(): void
    {
        $this->configuredWhatsapp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);

        $admin = $this->admin();
        Shoot::create([
            'title' => 'Warehouse day',
            'starts_at' => now()->subDays(3),
            'status' => Shoot::STATUS_COMPLETED,
        ]);

        $this->artisan('shoots:send-missing-content-alerts')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->data()['type'] === 'template'
            && $request->data()['template']['name'] === Shoot::WHATSAPP_TEMPLATE_MISSING_CONTENT
            && $request->data()['to'] === '919876543210');
    }

    public function test_staff_with_no_phone_get_no_whatsapp_attempt_for_missing_content(): void
    {
        Http::fake();
        $this->admin(phone: null);
        Shoot::create([
            'title' => 'Warehouse day',
            'starts_at' => now()->subDays(3),
            'status' => Shoot::STATUS_COMPLETED,
        ]);

        $this->artisan('shoots:send-missing-content-alerts');

        Http::assertNothingSent();
    }

    // -- content:send-depletion-alerts --------------------------------------

    public function test_a_client_going_critical_alerts_staff_with_a_phone_over_whatsapp(): void
    {
        $this->configuredWhatsapp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);

        $admin = $this->admin();
        $client = Client::create(['name' => 'SVA Silks and Readymades']);
        ContentAccount::create(['client_id' => $client->id, 'name' => 'Instagram', 'target_reel' => 12]);
        // No scheduled/in-progress reels and no upcoming shoot: remaining
        // is 0, depletion date is today -- critical (see
        // ContentForecast::forClient()).

        $this->artisan('content:send-depletion-alerts')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->data()['type'] === 'template'
            && $request->data()['template']['name'] === Client::WHATSAPP_TEMPLATE_DEPLETION
            && $request->data()['to'] === '919876543210');
    }

    public function test_staff_with_no_phone_get_no_whatsapp_attempt_for_depletion(): void
    {
        Http::fake();
        $this->admin(phone: null);
        $client = Client::create(['name' => 'SVA Silks and Readymades']);
        ContentAccount::create(['client_id' => $client->id, 'name' => 'Instagram', 'target_reel' => 12]);

        $this->artisan('content:send-depletion-alerts');

        Http::assertNothingSent();
    }
}
