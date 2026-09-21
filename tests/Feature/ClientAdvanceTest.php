<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAdvance;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function client(string $name = 'Riya Makeover'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function advance(Client $client, array $attributes = []): ClientAdvance
    {
        return ClientAdvance::create(array_merge([
            'client_id' => $client->id,
            'name' => 'September ad campaign',
            'amount' => 5000,
            'spent_on' => today(),
        ], $attributes));
    }

    public function test_an_advance_with_no_billable_amount_is_charged_at_cost(): void
    {
        $advance = $this->advance($this->client());

        $this->assertSame(5000.0, $advance->billable());
        $this->assertSame(0.0, $advance->margin());
    }

    public function test_a_billable_amount_overrides_the_cost(): void
    {
        $advance = $this->advance($this->client(), ['billable_amount' => 5750]);

        $this->assertSame(5750.0, $advance->billable());
        $this->assertSame(750.0, $advance->margin());
    }

    public function test_outstanding_counts_only_unrecovered_and_uses_the_billable_figure(): void
    {
        $client = $this->client();
        $this->advance($client, ['amount' => 5000, 'billable_amount' => 6000]);
        $this->advance($client, ['amount' => 2000]);
        $recovered = $this->advance($client, ['amount' => 9000]);
        $recovered->forceFill(['recovered_at' => now()])->save();

        $outstanding = ClientAdvance::outstanding()->get()
            ->sum(fn (ClientAdvance $a) => $a->billable());

        // 6000 (billable, not the 5000 paid) + 2000. The recovered 9000 is out.
        $this->assertSame(8000.0, $outstanding);
        $this->assertSame(2, ClientAdvance::outstanding()->count());
        $this->assertSame(1, ClientAdvance::recovered()->count());
    }

    public function test_the_register_shows_what_each_client_still_owes(): void
    {
        $riya = $this->client('Riya Makeover');
        $sva = $this->client('SVA Gold');
        $this->advance($riya, ['amount' => 5000]);
        $this->advance($sva, ['amount' => 1200]);

        $this->actingAs($this->admin())->get(route('client-advances.index'))
            ->assertOk()
            ->assertSee('Riya Makeover')
            ->assertSee('SVA Gold')
            ->assertSee('6,200'); // paid out in total
    }

    public function test_logging_an_advance_stores_it_against_the_client(): void
    {
        $client = $this->client();
        $category = TaxonomyTerm::create([
            'type' => TaxonomyTerm::TYPE_CLIENT_COST,
            'name' => 'Meta Ads',
            'slug' => 'meta-ads',
            'is_active' => true,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('client-advances.store'), [
            'client_id' => $client->id,
            'category_id' => $category->id,
            'name' => 'September ad campaign',
            'payee' => 'Meta',
            'amount' => 5000,
            'billable_amount' => 5750,
            'spent_on' => today()->toDateString(),
        ])->assertRedirect(route('client-advances.index'));

        $advance = ClientAdvance::firstOrFail();

        $this->assertSame($client->id, $advance->client_id);
        $this->assertSame('Meta', $advance->payee);
        $this->assertSame(5750.0, $advance->billable());
        $this->assertSame($admin->id, $advance->created_by_id);
        $this->assertFalse($advance->isRecovered());
    }

    public function test_marking_recovered_stamps_the_time_and_drops_it_out_of_outstanding(): void
    {
        $advance = $this->advance($this->client());

        $this->actingAs($this->admin())
            ->post(route('client-advances.recovered', $advance), ['recovered_note' => 'Invoice INV-2026-015'])
            ->assertRedirect();

        $advance->refresh();

        $this->assertTrue($advance->isRecovered());
        $this->assertSame('Invoice INV-2026-015', $advance->recovered_note);
        $this->assertSame(0, ClientAdvance::outstanding()->count());
    }

    public function test_recovery_can_be_undone(): void
    {
        $advance = $this->advance($this->client());
        $advance->forceFill(['recovered_at' => now(), 'recovered_note' => 'oops'])->save();

        $this->actingAs($this->admin())
            ->delete(route('client-advances.recovered.undo', $advance))
            ->assertRedirect();

        $this->assertFalse($advance->fresh()->isRecovered());
        $this->assertNull($advance->fresh()->recovered_note);
    }

    public function test_recovered_at_cannot_be_mass_assigned(): void
    {
        // Saying the money came back is a claim one action makes, never
        // something an edit-form post can assert in passing.
        $client = $this->client();
        $advance = new ClientAdvance;
        $advance->fill([
            'client_id' => $client->id,
            'name' => 'Sneaky',
            'amount' => 100,
            'spent_on' => today(),
            'recovered_at' => now(),
        ]);
        $advance->save();

        $this->assertFalse($advance->fresh()->isRecovered());
    }

    public function test_editing_cannot_smuggle_in_a_recovery(): void
    {
        $advance = $this->advance($this->client());

        $this->actingAs($this->admin())->put(route('client-advances.update', $advance), [
            'client_id' => $advance->client_id,
            'name' => 'Renamed',
            'amount' => 5000,
            'spent_on' => today()->toDateString(),
            'recovered_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $this->assertSame('Renamed', $advance->fresh()->name);
        $this->assertFalse($advance->fresh()->isRecovered());
    }

    public function test_a_receipt_is_stored_privately_and_streamed_back(): void
    {
        Storage::fake('local');
        $client = $this->client();

        $this->actingAs($this->admin())->post(route('client-advances.store'), [
            'client_id' => $client->id,
            'name' => 'Ad spend',
            'amount' => 3000,
            'spent_on' => today()->toDateString(),
            'receipt' => UploadedFile::fake()->image('bill.jpg'),
        ])->assertRedirect();

        $advance = ClientAdvance::firstOrFail();

        $this->assertNotNull($advance->receipt_path);
        $this->assertStringStartsWith('client-advances/', $advance->receipt_path);
        Storage::disk('local')->assertExists($advance->receipt_path);

        // Never written where a browser could reach it directly.
        $this->assertStringNotContainsString('uploads/', $advance->receipt_path);
        $this->assertFileDoesNotExist(public_path($advance->receipt_path));

        $this->actingAs($this->admin())
            ->get(route('client-advances.receipt', $advance))
            ->assertOk();
    }

    public function test_a_receipt_is_not_reachable_without_signing_in(): void
    {
        Storage::fake('local');
        $advance = $this->advance($this->client(), ['receipt_path' => 'client-advances/x.jpg']);

        $this->get(route('client-advances.receipt', $advance))->assertRedirect(route('login'));
    }

    public function test_staff_without_the_module_are_refused(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $advance = $this->advance($this->client());

        $this->actingAs($employee)->get(route('client-advances.index'))->assertForbidden();
        $this->actingAs($employee)->post(route('client-advances.recovered', $advance))->assertForbidden();
    }

    public function test_view_permission_alone_cannot_log_or_recover(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        UserPermission::create([
            'user_id' => $employee->id,
            'module' => 'client-advances',
            'ability' => 'view',
        ]);
        $advance = $this->advance($this->client());

        $this->actingAs($employee)->get(route('client-advances.index'))->assertOk();
        $this->actingAs($employee)->get(route('client-advances.create'))->assertForbidden();
        $this->actingAs($employee)->post(route('client-advances.recovered', $advance))->assertForbidden();
    }

    public function test_deleting_a_client_takes_its_advances_with_it(): void
    {
        $client = $this->client();
        $this->advance($client);

        $client->delete();

        $this->assertSame(0, ClientAdvance::count());
    }

    public function test_the_category_must_be_a_client_cost_term(): void
    {
        $wrongList = TaxonomyTerm::create([
            'type' => TaxonomyTerm::TYPE_LANGUAGE,
            'name' => 'Tamil',
            'slug' => 'tamil',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())->post(route('client-advances.store'), [
            'client_id' => $this->client()->id,
            'category_id' => $wrongList->id,
            'name' => 'Ad spend',
            'amount' => 1000,
            'spent_on' => today()->toDateString(),
        ])->assertSessionHasErrors('category_id');
    }
}
