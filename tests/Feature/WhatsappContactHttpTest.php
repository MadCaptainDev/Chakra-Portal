<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The HTTP surface for Contacts: CRUD through the resource routes, the
 * separate CSV import door, and the permission gating each per-action
 * middleware line in routes/web.php is supposed to enforce. See
 * WhatsappContactImportTest for the importer's own unit-level coverage --
 * the import test here only proves the controller wires it up correctly.
 */
class WhatsappContactHttpTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['whatsapp-crm' => $abilities]);

        return $user->refresh();
    }

    private function contact(array $overrides = []): WhatsappContact
    {
        return WhatsappContact::create($overrides + [
            'phone' => '917094126823',
            'name' => 'Ravi',
        ]);
    }

    // -- index ------------------------------------------------------------

    public function test_an_ungranted_employee_is_refused_the_index(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.contacts.index'))->assertForbidden();
    }

    public function test_a_user_with_view_can_list_contacts(): void
    {
        $this->contact(['name' => 'Ravi Kumar']);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.contacts.index'))
            ->assertOk()
            ->assertSee('Ravi Kumar');
    }

    public function test_the_index_can_be_filtered_to_one_phonebook(): void
    {
        $leads = WhatsappPhonebook::create(['name' => 'Leads']);
        $clients = WhatsappPhonebook::create(['name' => 'Clients']);

        $inLeads = $this->contact(['phone' => '917094126823', 'name' => 'In Leads']);
        $inClients = $this->contact(['phone' => '919876543210', 'name' => 'In Clients']);
        $leads->contacts()->attach($inLeads);
        $clients->contacts()->attach($inClients);

        $response = $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.contacts.index', ['phonebook_id' => $leads->id]));

        $response->assertOk()->assertSee('In Leads')->assertDontSee('In Clients');
    }

    // -- store --------------------------------------------------------------

    public function test_creating_a_contact_requires_the_create_ability(): void
    {
        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.contacts.store'), ['phone' => '7094126823'])
            ->assertForbidden();

        $this->assertSame(0, WhatsappContact::count());
    }

    public function test_the_create_and_edit_forms_render(): void
    {
        $contact = $this->contact();
        $user = $this->employee();

        $this->actingAs($user)->get(route('whatsapp-crm.contacts.create'))->assertOk();
        $this->actingAs($user)->get(route('whatsapp-crm.contacts.edit', $contact))->assertOk()->assertSee($contact->phone);
    }

    public function test_the_import_form_renders(): void
    {
        WhatsappPhonebook::create(['name' => 'Leads']);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.contacts.import.form'))
            ->assertOk()
            ->assertSee('Leads');
    }

    public function test_a_user_with_create_can_add_a_contact_and_attach_it_to_phonebooks(): void
    {
        $leads = WhatsappPhonebook::create(['name' => 'Leads']);

        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.contacts.store'), [
                'phone' => '7094126823',
                'name' => 'Ravi',
                'var1' => 'Friday',
                'phonebooks' => [$leads->id],
            ]);

        $response->assertRedirect(route('whatsapp-crm.contacts.index'));
        $contact = WhatsappContact::sole();

        // A bare 10-digit number is normalised to +91 the same way the model
        // mutator and WhatsappContactImporter both do it.
        $this->assertSame('917094126823', $contact->phone);
        $this->assertSame('Friday', $contact->var1);
        $this->assertTrue($contact->phonebooks->pluck('id')->contains($leads->id));
    }

    public function test_a_second_contact_with_the_same_number_in_a_different_format_is_rejected(): void
    {
        $this->contact(['phone' => '917094126823']);

        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.contacts.store'), ['phone' => '+91 70941 26823'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(1, WhatsappContact::count());
    }

    // -- update ---------------------------------------------------------------

    public function test_updating_a_contact_requires_the_edit_ability(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->employee(['view', 'create']))
            ->put(route('whatsapp-crm.contacts.update', $contact), ['phone' => $contact->phone, 'name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Ravi', $contact->fresh()->name);
    }

    public function test_a_user_with_edit_can_update_a_contact_and_resync_its_phonebooks(): void
    {
        $leads = WhatsappPhonebook::create(['name' => 'Leads']);
        $clients = WhatsappPhonebook::create(['name' => 'Clients']);
        $contact = $this->contact();
        $leads->contacts()->attach($contact);

        $response = $this->actingAs($this->employee(['view', 'edit']))
            ->put(route('whatsapp-crm.contacts.update', $contact), [
                'phone' => $contact->phone,
                'name' => 'Ravi Kumar',
                'phonebooks' => [$clients->id],
            ]);

        $response->assertRedirect(route('whatsapp-crm.contacts.index'));
        $contact->refresh();
        $this->assertSame('Ravi Kumar', $contact->name);
        $this->assertSame([$clients->id], $contact->phonebooks->pluck('id')->all());
    }

    // -- destroy ------------------------------------------------------------

    public function test_deleting_a_contact_requires_the_delete_ability(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->employee(['view', 'edit']))
            ->delete(route('whatsapp-crm.contacts.destroy', $contact))
            ->assertForbidden();

        $this->assertNotNull($contact->fresh());
    }

    public function test_a_user_with_delete_can_delete_a_contact(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->employee(['view', 'delete']))
            ->delete(route('whatsapp-crm.contacts.destroy', $contact))
            ->assertRedirect(route('whatsapp-crm.contacts.index'));

        $this->assertDatabaseMissing('whatsapp_contacts', ['id' => $contact->id]);
    }

    // -- show -----------------------------------------------------------------

    public function test_show_redirects_to_edit_since_there_is_no_separate_detail_page(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.contacts.show', $contact))
            ->assertRedirect(route('whatsapp-crm.contacts.edit', $contact));
    }

    // -- import ---------------------------------------------------------------

    public function test_importing_requires_the_create_ability(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $file = UploadedFile::fake()->createWithContent('contacts.csv', "Phone,Name\n7094126823,Ravi\n");

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.contacts.import'), ['file' => $file, 'phonebook_id' => $phonebook->id])
            ->assertForbidden();

        $this->assertSame(0, WhatsappContact::count());
    }

    public function test_importing_a_csv_end_to_end_creates_contacts_attaches_the_phonebook_and_flashes_the_summary(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $file = UploadedFile::fake()->createWithContent(
            'contacts.csv',
            "Phone,Name,Var1\n"
            ."7094126823,Ravi,Friday\n"
            ."not-a-number,Broken,\n"
        );

        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.contacts.import'), [
                'file' => $file,
                'phonebook_id' => $phonebook->id,
            ]);

        $response->assertRedirect(route('whatsapp-crm.contacts.import.form'));
        $result = $response->getSession()->get('import_result');
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['errors']);

        $ravi = WhatsappContact::where('phone', '917094126823')->sole();
        $this->assertSame('Friday', $ravi->var1);
        $this->assertTrue($ravi->phonebooks->pluck('id')->contains($phonebook->id));

        // The summary the redirect landed on shows the same counts.
        $this->actingAs($this->employee(['view', 'create']))
            ->get(route('whatsapp-crm.contacts.import.form'))
            ->assertSee('Leads');
    }

    public function test_the_import_form_rejects_a_non_csv_file(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $file = UploadedFile::fake()->create('contacts.pdf', 10, 'application/pdf');

        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.contacts.import'), ['file' => $file, 'phonebook_id' => $phonebook->id])
            ->assertSessionHasErrors('file');
    }
}
