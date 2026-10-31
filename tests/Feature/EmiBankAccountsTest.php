<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\User;
use App\Support\Banks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmiBankAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_the_old_free_text_banks_are_read_into_bank_and_holder(): void
    {
        $this->assertSame(['federal', 'Annamalai'], Banks::parse('FEDERAL - Annamalai'));
        $this->assertSame(['fi', 'Keerthi'], Banks::parse('Keerthi FI'));
        $this->assertSame(['canara', null], Banks::parse('CANA'));
        $this->assertSame(['boi', null], Banks::parse('BOI'));
        $this->assertSame([null, 'Thatha'], Banks::parse('Thatha'));
    }

    public function test_every_bank_has_its_logo_on_disk(): void
    {
        foreach (array_keys(Banks::ALL) as $key) {
            $this->assertFileExists(public_path('images/banks/'.$key.'.png'), $key);
        }
    }

    public function test_the_page_shows_logos_and_how_much_to_deposit_into_whose_account(): void
    {
        $federal = BankAccount::create(['bank' => 'federal', 'holder_name' => 'Annamalai', 'last4' => '1234']);
        foreach ([['Mac Mini', 5000], ['Sanjai 1 Lakh', 3000]] as [$name, $amount]) {
            Expense::create(['name' => $name, 'type' => Expense::TYPE_EMI, 'amount' => $amount, 'start_month' => '2026-09-01',
                'installments' => 10, 'is_active' => true, 'bank_account_id' => $federal->id, 'payee' => $federal->payeeText()]);
        }

        $this->actingAs($this->admin())->get(route('emi.index'))
            ->assertOk()
            ->assertSee('images/banks/federal.png')
            ->assertSee('Annamalai’s account', false)
            ->assertSee('Federal Bank ••1234', false)
            ->assertSee('8,000')
            ->assertSee('Deposit to');
    }

    public function test_an_emi_can_be_added_with_a_new_account_and_payee_follows_it(): void
    {
        $this->actingAs($this->admin())->post(route('emi.store'), [
            'name' => 'Sony lens', 'amount' => 4200, 'start_month' => '2026-11', 'installments' => 12,
            'bank_account_id' => 'new', 'new_bank' => 'axis', 'new_holder_name' => 'Vasan', 'new_last4' => '9876',
        ])->assertRedirect(route('emi.index'))->assertSessionHasNoErrors();

        $emi = Expense::where('name', 'Sony lens')->firstOrFail();
        $this->assertSame('Vasan · Axis Bank ••9876', $emi->bankAccount->label());
        $this->assertSame('Axis Bank - Vasan', $emi->payee);

        // Renaming the holder carries through to the EMI's readable text.
        $this->actingAs($this->admin())->put(route('emi.accounts.update', $emi->bankAccount), ['bank' => 'axis', 'holder_name' => 'Annamalai']);
        $this->assertSame('Axis Bank - Annamalai', $emi->fresh()->payee);
    }

    public function test_an_account_still_paying_emis_cannot_be_removed(): void
    {
        $account = BankAccount::create(['bank' => 'boi']);
        Expense::create(['name' => 'Gimbal', 'type' => Expense::TYPE_EMI, 'amount' => 1000, 'start_month' => '2026-10-01',
            'installments' => 3, 'is_active' => true, 'bank_account_id' => $account->id]);

        $this->actingAs($this->admin())->delete(route('emi.accounts.destroy', $account))->assertSessionHas('error');
        $this->assertModelExists($account);
    }
}
