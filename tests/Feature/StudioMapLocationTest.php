<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\Geocoder;
use App\Services\StudioMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioMapLocationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** Nominatim answers only for the queries given, nothing for the rest. */
    private function nominatim(array $answers): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => function (Request $request) use ($answers) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $hit = $answers[$query['q']] ?? null;

            return Http::response($hit ? [['lat' => (string) $hit[0], 'lon' => (string) $hit[1]]] : []);
        }]);
    }

    public function test_a_loose_address_is_retried_down_to_the_town(): void
    {
        $this->assertSame(
            ['No 9, SK Complex, Manapparai', 'SK Complex, Manapparai', 'Manapparai'],
            Geocoder::candidates("No 9, SK Complex,\nManapparai")
        );

        $this->nominatim(['Manapparai' => [10.6075, 78.425]]);
        $hit = app(Geocoder::class)->locate('Madurai Road , Manapparai');

        $this->assertSame(['lat' => 10.6075, 'lng' => 78.425, 'matched' => 'Manapparai'], $hit);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'countrycodes=in') && $r->hasHeader('User-Agent'));
    }

    public function test_the_nightly_lookup_places_new_and_edited_addresses_and_leaves_hand_placed_pins(): void
    {
        $this->nominatim(['Manapparai' => [10.6075, 78.425], 'Thillai Nagar, Trichy' => [10.8251, 78.6843]]);

        $fresh = Client::factory()->create(['address' => 'Manapparai']);
        $manual = Client::factory()->create(['address' => 'Manapparai']);
        $manual->forceFill(['latitude' => 11.0, 'longitude' => 79.0, 'location_source' => 'manual'])->save();
        $lost = Client::factory()->create(['address' => 'Nowhere Street']);
        Client::factory()->create(['address' => null]);

        $this->artisan('clients:geocode', ['--pause' => 0])->assertExitCode(0);

        $this->assertSame(10.6075, $fresh->fresh()->latitude);
        $this->assertSame('geocoded', $fresh->fresh()->location_source);
        $this->assertSame(11.0, $manual->fresh()->latitude, 'a hand-placed pin is never overwritten');
        $this->assertNull($lost->fresh()->latitude);

        // Not found is remembered: tonight's run does not ask about it again...
        Http::fake();
        $this->artisan('clients:geocode', ['--pause' => 0]);
        Http::assertNothingSent();

        // ...until the address is edited.
        $this->nominatim(['Thillai Nagar, Trichy' => [10.8251, 78.6843]]);
        $lost->update(['address' => 'Thillai Nagar, Trichy']);
        $this->artisan('clients:geocode', ['--pause' => 0]);
        $this->assertSame(10.8251, $lost->fresh()->latitude);
    }

    public function test_placing_a_pin_by_hand_marks_it_manual(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin())
            ->postJson(route('studio-map.actions.place'), ['target' => 'client', 'client_id' => $client->id, 'lat' => 10.61, 'lng' => 78.42])
            ->assertOk();

        $client->refresh();
        $this->assertSame(10.61, $client->latitude);
        $this->assertSame('manual', $client->location_source);
    }

    public function test_the_studio_pin_is_saved_and_a_click_outside_india_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('studio-map.actions.place'), ['target' => 'studio', 'lat' => 10.6075, 'lng' => 78.425])
            ->assertOk();
        $this->assertSame(['lat' => 10.6075, 'lng' => 78.425, 'set' => true], StudioMap::snapshot()['studio']);

        $this->actingAs($admin)
            ->postJson(route('studio-map.actions.place'), ['target' => 'studio', 'lat' => 51.5, 'lng' => -0.12])
            ->assertStatus(422);
        $this->assertEquals(10.6075, CompanySetting::current()->map_latitude);
    }

    public function test_looking_one_address_up_now(): void
    {
        $this->nominatim(['Manapparai' => [10.6075, 78.425]]);
        $client = Client::factory()->create(['name' => 'Zira', 'address' => 'Madurai Road, Manapparai']);
        $nowhere = Client::factory()->create(['address' => null]);

        $this->actingAs($this->admin())
            ->postJson(route('studio-map.actions.locate'), ['client_id' => $client->id])
            ->assertOk()
            ->assertJsonPath('message', 'Found Zira at Manapparai.');
        $this->assertSame(78.425, $client->fresh()->longitude);

        $this->actingAs($this->admin())
            ->postJson(route('studio-map.actions.locate'), ['client_id' => $nowhere->id])
            ->assertStatus(422);
    }

    public function test_employees_cannot_move_pins(): void
    {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->postJson(route('studio-map.actions.place'), ['target' => 'client', 'client_id' => $client->id, 'lat' => 10.6, 'lng' => 78.4])
            ->assertForbidden();
    }

    public function test_the_snapshot_says_where_each_client_is(): void
    {
        $client = Client::factory()->create(['name' => 'Zira', 'is_active' => true, 'address' => 'Manapparai']);
        $client->forceFill(['latitude' => 10.6075, 'longitude' => 78.425, 'location_source' => 'geocoded'])->save();

        $b = collect(StudioMap::snapshot()['buildings'])->firstWhere('name', 'Zira');

        $this->assertSame([10.6075, 78.425, 'geocoded', 'Manapparai'], [$b['lat'], $b['lng'], $b['pin'], $b['address']]);
        $this->assertFalse(StudioMap::snapshot()['studio']['set']);
    }
}
