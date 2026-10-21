<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShootReachedAlertTest extends TestCase
{
    use RefreshDatabase;

    private Shoot $shoot;

    private User $crew;

    protected function setUp(): void
    {
        parent::setUp();

        WhatsappSetting::current()->update(['access_token' => 'EAAG-test-token', 'phone_number_id' => '556677889900']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);

        $this->shoot = Shoot::create([
            'title' => 'SVA Silks reels', 'location' => 'Anna Nagar',
            'starts_at' => now()->addHour(), 'status' => Shoot::STATUS_CONFIRMED,
        ]);
        $this->crew = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Sanjai', 'phone' => '9000000001']);
        ShootCrew::create(['shoot_id' => $this->shoot->id, 'user_id' => $this->crew->id, 'role' => 'Director']);
    }

    private function sentTo(string $number): bool
    {
        return Http::recorded()->contains(fn ($pair) => ($pair[0]->data()['to'] ?? null) === $number
            && $pair[0]->data()['template']['name'] === Shoot::WHATSAPP_TEMPLATE_REACHED);
    }

    public function test_reaching_alerts_the_studio_but_not_the_person_who_tapped(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '9876543210']);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => null]);

        $this->actingAs($this->crew)->post(route('my.shoots.reached', $this->shoot))->assertRedirect();

        $this->assertTrue($this->sentTo('919876543210'));
        $this->assertFalse($this->sentTo('919000000001'), 'the person who tapped already knows');
        Http::assertSent(fn ($r) => ($r->data()['template']['components'][0]['parameters'][0]['text'] ?? null) === 'Sanjai'
            && $r->data()['template']['components'][0]['parameters'][2]['text'] === 'Anna Nagar');
    }

    public function test_a_second_tap_sends_nothing_more(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '9876543210']);

        $this->actingAs($this->crew)->post(route('my.shoots.reached', $this->shoot));
        $this->actingAs($this->crew)->post(route('my.shoots.reached', $this->shoot));
        $this->actingAs($this->crew)->post(route('my.shoots.start', $this->shoot));

        $this->assertCount(1, Http::recorded()->filter(fn ($pair) => ($pair[0]->data()['to'] ?? null) === '919876543210'));
    }

    public function test_starting_without_reached_still_alerts_once(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '9876543210']);

        $this->actingAs($this->crew)->post(route('my.shoots.start', $this->shoot))->assertRedirect();

        $this->assertTrue($this->sentTo('919876543210'));
    }

    public function test_a_failed_send_never_breaks_the_tap(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '9876543210']);

        $this->actingAs($this->crew)->post(route('my.shoots.reached', $this->shoot))->assertRedirect();

        $this->assertTrue($this->shoot->fresh()->hasReached());
    }
}
