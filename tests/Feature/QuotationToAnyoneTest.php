<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A quotation can go to anyone -- a name and a WhatsApp number -- not only
 * a client in the portal. Accepting and converting it makes them a client.
 */
class QuotationToAnyoneTest extends TestCase
{
    use RefreshDatabase;

    private function quote(User $user): Quotation
    {
        $this->actingAs($user)->post(route('quotations.store'), [
            'recipient_name' => 'Ganesh Bakery',
            'recipient_phone' => '98765 43210',
            'recipient_address' => 'Trichy',
            'quotation_date' => now()->format('Y-m-d'),
            'items' => [['description' => 'Brand reels', 'quantity' => 4, 'unit_price' => 5000]],
        ])->assertSessionHasNoErrors();

        return Quotation::firstOrFail();
    }

    public function test_a_quotation_for_someone_who_is_not_a_client(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $quotation = $this->quote($user);

        $this->assertNull($quotation->client_id);
        $this->assertSame('Ganesh Bakery', $quotation->recipientName());
        $this->assertSame('98765 43210', $quotation->recipientPhone());
        $this->assertEquals(20000, $quotation->total);

        $this->withoutVite();
        $this->actingAs($user)->get(route('quotations.show', $quotation))->assertOk()->assertSee('Ganesh Bakery')->assertSee('98765 43210');
        $this->actingAs($user)->get(route('quotations.index'))->assertOk()->assertSee('Ganesh Bakery');
        $this->actingAs($user)->get(route('quotations.index', ['search' => 'Ganesh']))->assertOk()->assertSee('Ganesh Bakery');
        $this->assertStringContainsString('Ganesh Bakery', view('quotations.document', ['quotation' => $quotation->load('items'), 'settings' => \App\Models\CompanySetting::current()])->render());
    }

    public function test_neither_a_client_nor_a_name_is_refused(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($user)->post(route('quotations.store'), [
            'quotation_date' => now()->format('Y-m-d'),
            'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertSessionHasErrors(['client_id', 'recipient_name']);
    }

    public function test_it_goes_on_whatsapp_with_their_name(): void
    {
        WhatsappSetting::current()->update(['access_token' => 't', 'phone_number_id' => '123456789', 'business_account_id' => '1']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $quotation = $this->quote($user);

        $this->actingAs($user)->post(route('quotations.send-whatsapp', $quotation), ['phone' => '9876543210'])->assertSessionHas('status');

        Http::assertSent(fn (Request $r) => $r['to'] === '919876543210'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Ganesh Bakery');
    }

    public function test_converting_it_makes_them_a_client(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $quotation = $this->quote($user);
        $this->actingAs($user)->post(route('quotations.accept', $quotation));

        $this->actingAs($user)->post(route('quotations.convert', $quotation))->assertRedirect();

        $client = Client::where('name', 'Ganesh Bakery')->firstOrFail();
        $this->assertSame('98765 43210', $client->phone);
        $this->assertSame($client->id, $quotation->fresh()->client_id);
        $this->assertSame($client->id, $quotation->fresh()->convertedInvoice->client_id);
    }
}
