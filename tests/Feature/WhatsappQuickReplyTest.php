<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappQuickReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saved replies CRUD for WhatsApp CRM -- no `show`, since the index row
 * already carries everything there is to see (see WhatsappQuickReplyController).
 */
class WhatsappQuickReplyTest extends TestCase
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

        $this->actingAs($employee)->get(route('whatsapp-crm.quick-replies.index'))->assertForbidden();
    }

    public function test_a_user_with_view_can_list_quick_replies(): void
    {
        WhatsappQuickReply::create(['title' => 'Booking confirmed', 'content' => 'Your shoot is booked!']);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.quick-replies.index'))
            ->assertOk()
            ->assertSee('Booking confirmed');
    }

    public function test_the_create_and_edit_forms_render(): void
    {
        $quickReply = WhatsappQuickReply::create(['title' => 'Booking confirmed', 'content' => 'Your shoot is booked!']);
        $user = $this->employee();

        $this->actingAs($user)->get(route('whatsapp-crm.quick-replies.create'))->assertOk();
        $this->actingAs($user)->get(route('whatsapp-crm.quick-replies.edit', $quickReply))->assertOk()->assertSee('Booking confirmed');
    }

    public function test_creating_a_quick_reply_requires_the_create_ability(): void
    {
        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.quick-replies.store'), ['title' => 'Booking confirmed', 'content' => 'Your shoot is booked!'])
            ->assertForbidden();

        $this->assertSame(0, WhatsappQuickReply::count());
    }

    public function test_a_user_with_create_can_create_a_quick_reply(): void
    {
        $user = $this->employee(['view', 'create']);

        $response = $this->actingAs($user)
            ->post(route('whatsapp-crm.quick-replies.store'), [
                'title' => 'Booking confirmed',
                'content' => 'Your shoot is booked!',
            ]);

        $response->assertRedirect(route('whatsapp-crm.quick-replies.index'));
        $quickReply = WhatsappQuickReply::sole();
        $this->assertSame('Booking confirmed', $quickReply->title);
        $this->assertSame('Your shoot is booked!', $quickReply->content);
        $this->assertSame($user->id, $quickReply->created_by_id);
    }

    public function test_a_quick_reply_requires_a_title_and_content(): void
    {
        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.quick-replies.store'), ['title' => '', 'content' => ''])
            ->assertSessionHasErrors(['title', 'content']);
    }

    public function test_updating_a_quick_reply_requires_the_edit_ability(): void
    {
        $quickReply = WhatsappQuickReply::create(['title' => 'Booking confirmed', 'content' => 'Your shoot is booked!']);

        $this->actingAs($this->employee(['view', 'create']))
            ->put(route('whatsapp-crm.quick-replies.update', $quickReply), ['title' => 'Renamed', 'content' => 'Updated message'])
            ->assertForbidden();

        $this->assertSame('Booking confirmed', $quickReply->fresh()->title);
    }

    public function test_a_user_with_edit_can_update_a_quick_reply(): void
    {
        $quickReply = WhatsappQuickReply::create(['title' => 'Booking confirmed', 'content' => 'Your shoot is booked!']);

        $this->actingAs($this->employee(['view', 'edit']))
            ->put(route('whatsapp-crm.quick-replies.update', $quickReply), ['title' => 'Renamed', 'content' => 'Updated message'])
            ->assertRedirect(route('whatsapp-crm.quick-replies.index'));

        $quickReply->refresh();
        $this->assertSame('Renamed', $quickReply->title);
        $this->assertSame('Updated message', $quickReply->content);
    }

    public function test_deleting_a_quick_reply_requires_the_delete_ability(): void
    {
        $quickReply = WhatsappQuickReply::create(['title' => 'Booking confirmed', 'content' => 'Your shoot is booked!']);

        $this->actingAs($this->employee(['view', 'edit']))
            ->delete(route('whatsapp-crm.quick-replies.destroy', $quickReply))
            ->assertForbidden();

        $this->assertNotNull($quickReply->fresh());
    }

    public function test_a_user_with_delete_can_delete_a_quick_reply(): void
    {
        $quickReply = WhatsappQuickReply::create(['title' => 'Booking confirmed', 'content' => 'Your shoot is booked!']);

        $this->actingAs($this->employee(['view', 'delete']))
            ->delete(route('whatsapp-crm.quick-replies.destroy', $quickReply))
            ->assertRedirect(route('whatsapp-crm.quick-replies.index'));

        $this->assertDatabaseMissing('whatsapp_quick_replies', ['id' => $quickReply->id]);
    }
}
