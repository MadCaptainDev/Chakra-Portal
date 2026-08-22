<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\SaasBackup;
use App\Models\SaasProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The session-authenticated admin half of the SaaS platform -- onboarding a
 * product (and seeing its token exactly once), suspending/reinstating, and
 * setting up AMC billing. See SaasBackupApiTest for the bearer-token half
 * this issues tokens for.
 */
class SaasProductAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function product(array $overrides = []): SaasProduct
    {
        $client = Client::create(['name' => 'DJ Thangamaaligai']);

        return SaasProduct::create($overrides + [
            'client_id' => $client->id,
            'name' => 'DJ Thangamaaligai ERP',
        ]);
    }

    public function test_creating_a_product_flashes_its_token_exactly_once(): void
    {
        $admin = $this->admin();
        $client = Client::create(['name' => 'Acme']);

        $response = $this->actingAs($admin)->post(route('saas-products.store'), [
            'client_id' => $client->id,
            'name' => 'Acme Storefront',
        ]);

        $product = SaasProduct::firstOrFail();
        $response->assertRedirect(route('saas-products.show', $product))
            ->assertSessionHas('saas_token_plain');
        $this->assertNotNull($product->token_hash);

        $plain = $response->getSession()->get('saas_token_plain');
        $this->assertTrue(str_starts_with($plain, SaasProduct::PREFIX));
        $this->assertSame($product->id, SaasProduct::resolveToken($plain)->id);

        // The page the redirect lands on shows it once...
        $this->actingAs($admin)->get(route('saas-products.show', $product))
            ->assertSee($plain)
            ->assertSessionMissing('saas_token_plain');

        // ...and a second visit must not, since it already aged out above.
        $this->actingAs($admin)->get(route('saas-products.show', $product))->assertDontSee($plain);
    }

    public function test_an_employee_without_the_module_cannot_reach_the_screen(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('saas-products.index'))->assertForbidden();
    }

    public function test_suspending_and_reinstating_a_product(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $this->actingAs($admin)->post(route('saas-products.suspend', $product));
        $this->assertTrue($product->refresh()->is_suspended);
        $this->assertSame($admin->id, $product->suspended_by_id);
        $this->assertSame('suspended', $product->status());

        $this->actingAs($admin)->post(route('saas-products.reinstate', $product));
        $this->assertFalse($product->refresh()->is_suspended);
        $this->assertNull($product->suspended_by_id);
    }

    public function test_deleting_a_product_removes_its_backup_files_from_disk(): void
    {
        Storage::fake('local');
        $product = $this->product();
        $product->backups()->create([
            'disk_path' => 'saas-backups/'.$product->id.'/x.sql',
            'size_bytes' => 5,
            'checksum' => str_repeat('a', 64),
            'taken_at' => now(),
        ]);
        Storage::disk('local')->put('saas-backups/'.$product->id.'/x.sql', 'hello');

        $this->actingAs($this->admin())->delete(route('saas-products.destroy', $product));

        $this->assertDatabaseMissing('saas_products', ['id' => $product->id]);
        $this->assertDatabaseMissing('saas_backups', ['saas_product_id' => $product->id]);
        Storage::disk('local')->assertMissing('saas-backups/'.$product->id.'/x.sql');
    }

    public function test_the_admin_can_download_a_backup_over_the_session(): void
    {
        Storage::fake('local');
        $product = $this->product();
        Storage::disk('local')->put('saas-backups/'.$product->id.'/x.sql', 'the bytes');
        $backup = $product->backups()->create([
            'disk_path' => 'saas-backups/'.$product->id.'/x.sql',
            'size_bytes' => 9,
            'checksum' => str_repeat('a', 64),
            'taken_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('saas-products.backups.download', [$product, $backup]))
            ->assertOk();
    }

    public function test_setting_up_amc_creates_a_yearly_recurring_schedule_linked_to_the_product(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post(route('saas-products.setup-amc', $product), [
            'amount' => 12000,
            'frequency' => 'yearly',
            'next_run_on' => now()->addMonth()->format('Y-m-d'),
            'due_days' => 14,
        ]);

        $product->refresh();
        $this->assertNotNull($product->recurring_invoice_id);
        $this->assertSame('yearly', $product->recurringInvoice->frequency);
        $this->assertSame($product->id, $product->recurringInvoice->saas_product_id);
        $this->assertSame(12000.0, (float) $product->recurringInvoice->items->first()->unit_price);
    }

    public function test_amc_can_be_billed_monthly_instead_of_yearly(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post(route('saas-products.setup-amc', $product), [
            'amount' => 1500,
            'frequency' => 'monthly',
            'next_run_on' => now()->addWeek()->format('Y-m-d'),
        ]);

        $this->assertSame('monthly', $product->refresh()->recurringInvoice->frequency);
    }

    public function test_amc_billing_cannot_be_set_up_twice(): void
    {
        $product = $this->product();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('saas-products.setup-amc', $product), [
            'amount' => 12000,
            'frequency' => 'yearly',
            'next_run_on' => now()->addMonth()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($admin)->post(route('saas-products.setup-amc', $product->refresh()), [
            'amount' => 5000,
            'frequency' => 'yearly',
            'next_run_on' => now()->addMonth()->format('Y-m-d'),
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, \App\Models\RecurringInvoice::count());
    }

    /**
     * The other end of AMC billing: paying the generated invoice in full
     * extends the product's amc_paid_until. Lives here rather than
     * PaymentTest because it is specifically about the SaasProduct side
     * effect, not payment recording itself.
     */
    public function test_paying_an_amc_invoice_in_full_extends_the_products_amc_date(): void
    {
        $product = $this->product();
        $invoice = Invoice::factory()->create([
            'subtotal' => 12000, 'total' => 12000, 'saas_product_id' => $product->id, 'saas_invoice_type' => Invoice::STUDIO_TYPE_AMC,
        ]);

        $this->actingAs($this->admin())->post(route('payments.store', $invoice), [
            'amount' => 12000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $this->assertNotNull($product->refresh()->amc_paid_until);
        $this->assertTrue($product->amc_paid_until->isSameDay(today()->addYear()));
    }

    public function test_a_monthly_amc_schedule_extends_amc_paid_until_by_a_month_not_a_year(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post(route('saas-products.setup-amc', $product), [
            'amount' => 1500,
            'frequency' => 'monthly',
            'next_run_on' => now()->addWeek()->format('Y-m-d'),
        ]);
        $product->refresh();

        $invoice = Invoice::factory()->create([
            'subtotal' => 1500, 'total' => 1500, 'saas_product_id' => $product->id, 'saas_invoice_type' => Invoice::STUDIO_TYPE_AMC,
        ]);

        $this->actingAs($this->admin())->post(route('payments.store', $invoice), [
            'amount' => 1500,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $this->assertTrue($product->refresh()->amc_paid_until->isSameDay(today()->addMonthNoOverflow()));
    }

    public function test_extending_amc_before_it_lapses_adds_to_the_remaining_term_rather_than_resetting_it(): void
    {
        $product = $this->product(['amc_paid_until' => now()->addMonths(6)->toDateString()]);
        $invoice = Invoice::factory()->create([
            'subtotal' => 12000, 'total' => 12000, 'saas_product_id' => $product->id, 'saas_invoice_type' => Invoice::STUDIO_TYPE_AMC,
        ]);

        $this->actingAs($this->admin())->post(route('payments.store', $invoice), [
            'amount' => 12000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $product->refresh();
        $this->assertTrue($product->amc_paid_until->isSameDay(now()->addMonths(6)->addYear()));
    }

    public function test_paying_an_ordinary_invoice_never_touches_any_saas_product(): void
    {
        $invoice = Invoice::factory()->create(['subtotal' => 5000, 'total' => 5000]);

        $this->actingAs($this->admin())->post(route('payments.store', $invoice), [
            'amount' => 5000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(0, SaasProduct::count());
    }

    public function test_the_invoices_screen_filters_app_studio_apart_from_production_work(): void
    {
        $product = $this->product();
        $studioInvoice = Invoice::factory()->create([
            'saas_product_id' => $product->id, 'saas_invoice_type' => Invoice::STUDIO_TYPE_AMC, 'invoice_date' => now(),
        ]);
        $productionInvoice = Invoice::factory()->create(['invoice_date' => now()]);

        $response = $this->actingAs($this->admin())->get(route('invoices.index', ['type' => 'studio']));

        $response->assertOk()
            ->assertSee($studioInvoice->invoice_number)
            ->assertDontSee($productionInvoice->invoice_number);

        $response = $this->actingAs($this->admin())->get(route('invoices.index', ['type' => 'production']));

        $response->assertOk()
            ->assertSee($productionInvoice->invoice_number)
            ->assertDontSee($studioInvoice->invoice_number);
    }

    public function test_the_invoices_screen_can_narrow_to_just_amc_or_just_development(): void
    {
        $product = $this->product();
        $amcInvoice = Invoice::factory()->create([
            'saas_product_id' => $product->id, 'saas_invoice_type' => Invoice::STUDIO_TYPE_AMC, 'invoice_date' => now(),
        ]);
        $devInvoice = Invoice::factory()->create([
            'saas_product_id' => $product->id, 'saas_invoice_type' => Invoice::STUDIO_TYPE_DEVELOPMENT, 'invoice_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('invoices.index', ['type' => 'amc']));
        $response->assertOk()->assertSee($amcInvoice->invoice_number)->assertDontSee($devInvoice->invoice_number);

        $response = $this->actingAs($this->admin())->get(route('invoices.index', ['type' => 'development']));
        $response->assertOk()->assertSee($devInvoice->invoice_number)->assertDontSee($amcInvoice->invoice_number);
    }

    /**
     * The correction that matters most: a development invoice being paid
     * must never extend AMC -- only an actual AMC invoice does that. Lives
     * here rather than PaymentTest for the same reason the AMC-side tests
     * do: this is specifically about the SaasProduct side effect.
     */
    public function test_paying_a_development_invoice_never_extends_amc(): void
    {
        $product = $this->product(['amc_paid_until' => now()->addMonth()->toDateString()]);
        $invoice = Invoice::factory()->create([
            'subtotal' => 5000, 'total' => 5000,
            'saas_product_id' => $product->id, 'saas_invoice_type' => Invoice::STUDIO_TYPE_DEVELOPMENT,
        ]);
        $originalAmcDate = $product->amc_paid_until;

        $this->actingAs($this->admin())->post(route('payments.store', $invoice), [
            'amount' => 5000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $this->assertTrue($product->refresh()->amc_paid_until->isSameDay($originalAmcDate));
    }

    public function test_creating_an_app_studio_invoice_requires_choosing_amc_or_development(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post(route('invoices.store'), [
            'client_id' => $product->client_id,
            'saas_product_id' => $product->id,
            // saas_invoice_type deliberately omitted.
            'invoice_date' => now()->format('Y-m-d'),
            'items' => [['description' => 'App Studio work', 'quantity' => 1, 'unit_price' => 3000]],
        ]);

        $response->assertSessionHasErrors('saas_invoice_type');
        $this->assertSame(0, Invoice::count());
    }

    public function test_creating_a_development_invoice_tags_it_without_touching_amc(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post(route('invoices.store'), [
            'client_id' => $product->client_id,
            'saas_product_id' => $product->id,
            'saas_invoice_type' => Invoice::STUDIO_TYPE_DEVELOPMENT,
            'invoice_date' => now()->format('Y-m-d'),
            'items' => [['description' => 'New feature build', 'quantity' => 1, 'unit_price' => 20000]],
        ]);

        $invoice = Invoice::firstOrFail();
        $this->assertSame($product->id, $invoice->saas_product_id);
        $this->assertSame(Invoice::STUDIO_TYPE_DEVELOPMENT, $invoice->saas_invoice_type);
    }

    public function test_an_amc_recurring_schedule_carries_its_product_onto_every_invoice_it_generates(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post(route('saas-products.setup-amc', $product), [
            'amount' => 12000,
            'frequency' => 'yearly',
            'next_run_on' => now()->format('Y-m-d'),
        ]);

        // Backdated after creation -- the form itself refuses a past
        // next_run_on (after_or_equal:today), so this is the only way to
        // get a freshly created schedule into "already due".
        $product->refresh()->recurringInvoice->update(['next_run_on' => now()->subDay()]);

        // Called directly
        // rather than through EnsureRecurringInvoicesGenerated's
        // once-per-day Cache::add() gate -- an earlier test in this run may
        // already have claimed today's run, which would make this test's
        // outcome depend on suite ordering rather than on what it is
        // actually about.
        app(\App\Services\RecurringInvoiceGenerator::class)->run();

        $generated = Invoice::where('saas_product_id', $product->id)->first();
        $this->assertNotNull($generated);
        $this->assertSame($product->recurring_invoice_id, $generated->recurring_invoice_id);
    }

    public function test_reissuing_a_token_retires_the_old_one_and_flashes_the_new_one_once(): void
    {
        $product = $this->product();
        $oldPlain = $product->issueToken();
        $oldHash = $product->token_hash;

        $response = $this->actingAs($this->admin())->post(route('saas-products.reissue-token', $product));

        $response->assertRedirect()->assertSessionHas('saas_token_plain');
        $newPlain = $response->getSession()->get('saas_token_plain');

        $this->assertNotSame($oldPlain, $newPlain);
        $this->assertNotSame($oldHash, $product->refresh()->token_hash);
        $this->assertNull(SaasProduct::resolveToken($oldPlain));
        $this->assertSame($product->id, SaasProduct::resolveToken($newPlain)->id);
    }

    public function test_creating_an_invoice_can_tag_it_as_app_studio_work(): void
    {
        $product = $this->product();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('invoices.store'), [
            'client_id' => $product->client_id,
            'saas_product_id' => $product->id,
            'saas_invoice_type' => Invoice::STUDIO_TYPE_AMC,
            'invoice_date' => now()->format('Y-m-d'),
            'items' => [['description' => 'App Studio support', 'quantity' => 1, 'unit_price' => 3000]],
        ]);

        $response->assertRedirect();
        $invoice = Invoice::firstOrFail();
        $this->assertSame($product->id, $invoice->saas_product_id);
    }

    public function test_an_ordinary_invoice_is_not_tagged_unless_a_saas_product_is_chosen(): void
    {
        $client = Client::create(['name' => 'Some Production Client']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('invoices.store'), [
            'client_id' => $client->id,
            'invoice_date' => now()->format('Y-m-d'),
            'items' => [['description' => 'Reel edit', 'quantity' => 1, 'unit_price' => 5000]],
        ]);

        $this->assertNull(Invoice::firstOrFail()->saas_product_id);
    }

    public function test_the_index_shows_app_studio_income_totals(): void
    {
        $product = $this->product();
        Invoice::factory()->create([
            'saas_product_id' => $product->id, 'total' => 12000, 'subtotal' => 12000,
            'status' => Invoice::STATUS_PAID, 'invoice_date' => now(),
        ]);
        Invoice::factory()->create([
            'total' => 5000, 'subtotal' => 5000, 'status' => Invoice::STATUS_PAID, 'invoice_date' => now(),
        ]);

        $this->actingAs($this->admin())->get(route('saas-products.index'))
            ->assertOk()
            ->assertSee('12,000.00');
    }
}
