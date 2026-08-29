<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The named-list CRUD for WhatsApp CRM -- no `show`, since a phonebook has
 * nothing of its own worth a detail page (see WhatsappPhonebookController).
 */
class WhatsappPhonebookTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['whatsapp-crm' => $abilities]);

        return $user->refresh();
    }

    public function test_an_ungranted_employee_is_refused_the_index(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.phonebooks.index'))->assertForbidden();
    }

    public function test_a_user_with_view_can_list_phonebooks_and_their_contact_counts(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $contact = WhatsappContact::create(['phone' => '917094126823', 'name' => 'Ravi']);
        $phonebook->contacts()->attach($contact);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.phonebooks.index'))
            ->assertOk()
            ->assertSee('Leads')
            ->assertSee('1 contact');
    }

    public function test_the_create_and_edit_forms_render(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $user = $this->employee();

        $this->actingAs($user)->get(route('whatsapp-crm.phonebooks.create'))->assertOk();
        $this->actingAs($user)->get(route('whatsapp-crm.phonebooks.edit', $phonebook))->assertOk()->assertSee('Leads');
    }

    public function test_creating_a_phonebook_requires_the_create_ability(): void
    {
        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.phonebooks.store'), ['name' => 'Leads'])
            ->assertForbidden();

        $this->assertSame(0, WhatsappPhonebook::count());
    }

    public function test_a_user_with_create_can_create_a_phonebook(): void
    {
        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.phonebooks.store'), [
                'name' => 'Leads',
                'description' => 'Warm inbound enquiries',
            ]);

        $response->assertRedirect(route('whatsapp-crm.phonebooks.index'));
        $phonebook = WhatsappPhonebook::sole();
        $this->assertSame('Leads', $phonebook->name);
        $this->assertSame('Warm inbound enquiries', $phonebook->description);
    }

    public function test_a_phonebook_requires_a_name(): void
    {
        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.phonebooks.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_updating_a_phonebook_requires_the_edit_ability(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        $this->actingAs($this->employee(['view', 'create']))
            ->put(route('whatsapp-crm.phonebooks.update', $phonebook), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Leads', $phonebook->fresh()->name);
    }

    public function test_a_user_with_edit_can_rename_a_phonebook(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        $this->actingAs($this->employee(['view', 'edit']))
            ->put(route('whatsapp-crm.phonebooks.update', $phonebook), ['name' => 'Renamed'])
            ->assertRedirect(route('whatsapp-crm.phonebooks.index'));

        $this->assertSame('Renamed', $phonebook->fresh()->name);
    }

    public function test_deleting_a_phonebook_requires_the_delete_ability(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        $this->actingAs($this->employee(['view', 'edit']))
            ->delete(route('whatsapp-crm.phonebooks.destroy', $phonebook))
            ->assertForbidden();

        $this->assertNotNull($phonebook->fresh());
    }

    /**
     * Deleting a phonebook only removes the pivot rows -- the contact itself
     * stays in the CRM, just no longer grouped under this list.
     */
    public function test_a_user_with_delete_can_delete_a_phonebook_without_removing_its_contacts(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $contact = WhatsappContact::create(['phone' => '917094126823', 'name' => 'Ravi']);
        $phonebook->contacts()->attach($contact);

        $this->actingAs($this->employee(['view', 'delete']))
            ->delete(route('whatsapp-crm.phonebooks.destroy', $phonebook))
            ->assertRedirect(route('whatsapp-crm.phonebooks.index'));

        $this->assertDatabaseMissing('whatsapp_phonebooks', ['id' => $phonebook->id]);
        $this->assertDatabaseHas('whatsapp_contacts', ['id' => $contact->id]);
    }
}
