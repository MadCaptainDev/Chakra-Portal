<?php

namespace Tests\Unit;

use App\Support\CityDistricts;
use PHPUnit\Framework\TestCase;

class CityDistrictsTest extends TestCase
{
    private const NO_STUDIO = ['lat' => null, 'lng' => null, 'set' => false];

    private function client(int $id, ?float $lat, ?float $lng, ?string $address): array
    {
        return compact('id', 'lat', 'lng', 'address');
    }

    public function test_the_town_an_address_ends_in(): void
    {
        $this->assertSame('Manapparai', CityDistricts::townFrom('Madurai Road , Manapparai'));
        $this->assertSame('Trichy', CityDistricts::townFrom('No9/286, SK, Complex, Near By Signal, Chennai Bypass Road, No1 Tollgate, Trichy-621216'));
        $this->assertSame('Chennai', CityDistricts::townFrom('Chennai , Tamil Nadu'));
        $this->assertSame('Kannudayanpatti', CityDistricts::townFrom('Check Post , Dindigul Main Road , Kannudayanpatti , Tamil nadu 621306'));
        $this->assertNull(CityDistricts::townFrom('  '));
        $this->assertNull(CityDistricts::townFrom('15/4427'));
    }

    public function test_neighbours_share_a_district_named_after_their_town(): void
    {
        $plan = CityDistricts::build([
            $this->client(1, 10.6105, 78.4182, 'Madurai Road , Manapparai'),
            $this->client(2, 10.6150, 78.4200, 'Vaiyamppati , Manapparai'),
            $this->client(3, 10.8251, 78.6843, 'Thillai Nagar , Trichy'),
        ], self::NO_STUDIO);

        $this->assertSame($plan['of'][1], $plan['of'][2]);
        $this->assertNotSame($plan['of'][1], $plan['of'][3]);
        $names = array_column($plan['districts'], 'name', 'key');
        $this->assertSame('Manapparai', $names[$plan['of'][1]]);
        $this->assertSame('Trichy', $names[$plan['of'][3]]);
    }

    public function test_the_biggest_district_is_home_without_a_studio_pin_and_others_lie_by_bearing(): void
    {
        $plan = CityDistricts::build([
            $this->client(1, 10.61, 78.42, 'Manapparai'),
            $this->client(2, 10.61, 78.42, 'Manapparai'),
            $this->client(3, 13.08, 80.27, 'Chennai'),   // north-east
            $this->client(4, 10.36, 77.98, 'Dindigul'),  // south-west
        ], self::NO_STUDIO);

        $slots = array_column($plan['districts'], 'slot', 'name');
        $this->assertSame('manapparai', $plan['home']);
        $this->assertSame([0, 0], $slots['Manapparai']);
        $this->assertSame([0, -1], $slots['Chennai'], 'north-east is up and to the right');
        $this->assertSame([0, 1], $slots['Dindigul'], 'south-west is down and to the left');
    }

    public function test_the_studio_pin_decides_home_and_clients_without_a_location_find_their_town(): void
    {
        $plan = CityDistricts::build([
            $this->client(1, 10.61, 78.42, 'Manapparai'),
            $this->client(2, 10.82, 78.68, 'Thillai Nagar, Trichy'),
            $this->client(3, null, null, 'Somewhere, Trichy'),
            $this->client(4, null, null, null),
        ], ['lat' => 10.80, 'lng' => 78.69, 'set' => true]);

        $this->assertSame('trichy', $plan['home']);
        $this->assertSame('trichy', $plan['of'][3], 'its address names Trichy');
        $this->assertSame('trichy', $plan['of'][4], 'no address: home');
        $this->assertCount(4, $plan['of']);
    }

    public function test_no_clients_at_all_still_gives_a_home(): void
    {
        $plan = CityDistricts::build([], self::NO_STUDIO);

        $this->assertSame([['key' => 'chakra', 'name' => 'Chakra', 'slot' => [0, 0], 'home' => true, 'count' => 0]], $plan['districts']);
    }

    public function test_more_districts_than_a_ring_holds_spill_into_the_next(): void
    {
        $clients = [];
        foreach (range(0, 9) as $i) {
            $angle = deg2rad($i * 36);
            $clients[] = $this->client($i + 1, 10.6 + sin($angle) * ($i ? 0.5 : 0), 78.4 + cos($angle) * ($i ? 0.5 : 0), "Town {$i}");
        }
        $clients[] = $this->client(99, 10.6, 78.4, 'Town 0');

        $plan = CityDistricts::build($clients, self::NO_STUDIO);
        $slots = array_map(fn ($d) => implode(',', $d['slot']), $plan['districts']);

        $this->assertCount(10, $plan['districts']);
        $this->assertSame(count($slots), count(array_unique($slots)), 'no two districts share a cell');
    }
}
