<?php

namespace Tests\Feature;

use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_contact_can_be_created_read_updated_and_deleted(): void
    {
        $contact = WhatsappContact::create([
            'phone' => '7094126823',
            'name' => 'Ravi',
        ]);

        $this->assertDatabaseHas('whatsapp_contacts', [
            'id' => $contact->id,
            'name' => 'Ravi',
        ]);

        $found = WhatsappContact::find($contact->id);
        $this->assertSame('Ravi', $found->name);

        $found->update(['name' => 'Ravi Kumar']);
        $this->assertSame('Ravi Kumar', $found->fresh()->name);

        $found->delete();
        $this->assertDatabaseMissing('whatsapp_contacts', ['id' => $contact->id]);
    }

    /**
     * The one guarantee this model makes: whatever door a phone number comes
     * in through, it lands in the database the same way WhatsappSender will
     * ask Meta to send to it.
     */
    public function test_the_phone_number_is_normalised_on_save(): void
    {
        $contact = WhatsappContact::create(['phone' => '+91 70941 26823']);

        $this->assertSame('917094126823', $contact->phone);
        $this->assertSame('917094126823', $contact->fresh()->phone);
    }

    public function test_a_bare_ten_digit_number_picks_up_its_country_code(): void
    {
        $contact = WhatsappContact::create(['phone' => '7094126823']);

        $this->assertSame('917094126823', $contact->phone);
    }

    public function test_a_contact_can_be_attached_to_and_detached_from_a_phonebook(): void
    {
        $contact = WhatsappContact::create(['phone' => '7094126823']);
        $phonebook = WhatsappPhonebook::create(['name' => 'VIP Clients']);

        $phonebook->contacts()->attach($contact);

        $this->assertSame(1, $phonebook->contactsCount());
        $this->assertTrue($contact->phonebooks->pluck('id')->contains($phonebook->id));

        $phonebook->contacts()->detach($contact);

        $this->assertSame(0, $phonebook->fresh()->contactsCount());
    }

    public function test_a_contact_can_belong_to_more_than_one_phonebook(): void
    {
        $contact = WhatsappContact::create(['phone' => '7094126823']);
        $vip = WhatsappPhonebook::create(['name' => 'VIP Clients']);
        $leads = WhatsappPhonebook::create(['name' => 'Leads']);

        $contact->phonebooks()->attach([$vip->id, $leads->id]);

        $this->assertSame(2, $contact->phonebooks()->count());
        $this->assertSame(1, $vip->contactsCount());
        $this->assertSame(1, $leads->contactsCount());
    }

    public function test_merge_fields_returns_the_five_positional_variables(): void
    {
        $contact = WhatsappContact::create([
            'phone' => '7094126823',
            'var1' => 'Ravi',
            'var2' => 'Friday',
            'var3' => '4pm',
        ]);

        $this->assertSame([
            'var1' => 'Ravi',
            'var2' => 'Friday',
            'var3' => '4pm',
            'var4' => null,
            'var5' => null,
        ], $contact->mergeFields());
    }
}
