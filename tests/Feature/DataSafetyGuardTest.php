<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataSafetyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function paidInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);
        $invoice->payments()->create([
            'amount' => 4000,
            'paid_on' => now()->toDateString(),
            'recorded_by' => User::factory()->create()->id,
        ]);

        return $invoice;
    }

    public function test_deleting_an_invoice_with_payments_is_refused(): void
    {
        $invoice = $this->paidInvoice();

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('invoices.destroy', $invoice));

        $response->assertSessionHas('error');

        // payments.invoice_id cascades, so a successful delete would have taken
        // the payment record with it.
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_deleting_an_invoice_without_payments_still_works(): void
    {
        $invoice = Invoice::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('invoices.destroy', $invoice))
            ->assertRedirect(route('invoices.index'));

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_a_payment_above_the_outstanding_balance_is_refused(): void
    {
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('payments.store', $invoice), [
                'amount' => 15000,
                'paid_on' => now()->toDateString(),
            ]);

        // The ceiling lives in the validation rule, so the refusal comes back
        // as an error on the amount field rather than a flashed message.
        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(10000.0, $invoice->fresh()->balanceDue());
    }

    public function test_a_payment_equal_to_the_balance_is_accepted(): void
    {
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        $this->actingAs(User::factory()->create())
            ->post(route('payments.store', $invoice), [
                'amount' => 10000,
                'paid_on' => now()->toDateString(),
            ])
            ->assertSessionHas('status');

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0.0, $invoice->balanceDue());
    }

    public function test_a_second_payment_may_only_cover_the_remaining_balance(): void
    {
        $invoice = $this->paidInvoice(); // 10,000 total, 4,000 already paid
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 7000, // more than the 6,000 left
            'paid_on' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 6000,
            'paid_on' => now()->toDateString(),
        ])->assertSessionHas('status');

        $this->assertSame(0.0, $invoice->fresh()->balanceDue());
    }

    public function test_replacing_a_logo_deletes_the_previous_upload(): void
    {
        Storage::fake('public');

        $settings = CompanySetting::current();
        $user = User::factory()->create();

        $base = [
            'company_name' => 'Chakra Productions',
            'signature_name' => 'Owner',
            'signature_title' => 'CEO',
            'invoice_prefix' => 'CP-',
            'footer_text' => 'Thanks',
        ];

        $this->actingAs($user)->put(route('settings.update'), $base + [
            'logo' => UploadedFile::fake()->image('first.png'),
        ]);

        $first = $settings->fresh()->logo_path;
        Storage::disk('public')->assertExists(substr($first, strlen('storage/')));

        $this->actingAs($user)->put(route('settings.update'), $base + [
            'logo' => UploadedFile::fake()->image('second.png'),
        ]);

        $second = $settings->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing(substr($first, strlen('storage/')));
        Storage::disk('public')->assertExists(substr($second, strlen('storage/')));
    }

    public function test_the_bundled_default_logo_is_never_deleted(): void
    {
        Storage::fake('public');

        $settings = CompanySetting::current();
        // The seeded default lives in public/images, not on the storage disk.
        $this->assertSame('images/chakra-logo.png', $settings->logo_path);

        $this->actingAs(User::factory()->create())->put(route('settings.update'), [
            'company_name' => 'Chakra Productions',
            'signature_name' => 'Owner',
            'signature_title' => 'CEO',
            'invoice_prefix' => 'CP-',
            'footer_text' => 'Thanks',
            'logo' => UploadedFile::fake()->image('new.png'),
        ]);

        // Still on disk in the repo -- deleting it would strip the logo from
        // every invoice for anyone who never uploaded one.
        $this->assertFileExists(public_path('images/chakra-logo.png'));
    }

    public function test_editing_a_paused_bill_does_not_silently_reactivate_it(): void
    {
        $bill = Expense::create([
            'name' => 'Wifi Bill',
            'type' => Expense::TYPE_BILL,
            'amount' => 3200,
            'is_active' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('bills.update', $bill), [
                'name' => 'Wifi Bill',
                'amount' => 3400,
                'is_active' => '0',
            ]);

        $bill->refresh();
        $this->assertFalse($bill->is_active);
        $this->assertSame(3400.00, (float) $bill->amount);
    }
}
