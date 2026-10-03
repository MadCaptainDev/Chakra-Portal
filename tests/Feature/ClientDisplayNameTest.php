<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_website_shows_the_display_name_and_the_books_keep_the_name(): void
    {
        $client = Client::create(['name' => 'Digital Harvest (Janet Hospitals)']);

        $this->actingAs(User::factory()->create())
            ->patch(route('clients.display-name', $client), ['display_name' => '  Janet Hospitals Trichy '])
            ->assertRedirect(route('clients.edit', $client));

        $client->refresh();
        $this->assertSame('Digital Harvest (Janet Hospitals)', $client->name);
        $this->assertSame('Janet Hospitals Trichy', $client->publicName());

        $item = PortfolioItem::create(['title' => 'Newborn care', 'is_visible' => true, 'client_id' => $client->id]);
        $this->assertSame('Janet Hospitals Trichy', $item->clientLabel());

        $this->get(route('portfolio'))->assertSee('Janet Hospitals Trichy')->assertDontSee('Digital Harvest');
    }

    public function test_blank_or_same_as_the_name_clears_it(): void
    {
        $client = Client::create(['name' => 'SVA Gold and Diamonds', 'display_name' => 'SVA']);
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('clients.display-name', $client), ['display_name' => '']);
        $this->assertNull($client->refresh()->display_name);

        $this->actingAs($user)->patch(route('clients.display-name', $client), ['display_name' => 'SVA Gold and Diamonds']);
        $this->assertNull($client->refresh()->display_name);
        $this->assertSame('SVA Gold and Diamonds', $client->publicName());
    }

    public function test_the_edit_page_carries_the_website_name_form(): void
    {
        $client = Client::create(['name' => 'Digital Harvest (Janet Hospitals)']);

        $this->actingAs(User::factory()->create())
            ->get(route('clients.edit', $client))
            ->assertOk()
            ->assertSee('On the website')
            ->assertSee(route('clients.display-name', $client), false);
    }
}
