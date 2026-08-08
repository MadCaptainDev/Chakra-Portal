<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnquiryInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_inbox_lists_enquiries_newest_first(): void
    {
        $user = User::factory()->create();

        $older = Enquiry::factory()->create(['name' => 'Older Lead', 'created_at' => now()->subWeek()]);
        $newer = Enquiry::factory()->create(['name' => 'Newer Lead', 'created_at' => now()]);

        $response = $this->actingAs($user)->get(route('enquiries.index'));

        $response->assertOk();
        $response->assertSeeInOrder([$newer->name, $older->name]);
    }

    public function test_the_inbox_filters_to_unread_and_open(): void
    {
        $user = User::factory()->create();

        $unread = Enquiry::factory()->create(['name' => 'Unread Lead']);
        $read = Enquiry::factory()->read()->create(['name' => 'Read Lead']);
        $handled = Enquiry::factory()->handled()->create(['name' => 'Handled Lead']);

        $this->actingAs($user)->get(route('enquiries.index', ['filter' => 'unread']))
            ->assertSee($unread->name)
            ->assertDontSee($read->name)
            ->assertDontSee($handled->name);

        // "Open" is anything not yet dealt with, read or not.
        $this->actingAs($user)->get(route('enquiries.index', ['filter' => 'open']))
            ->assertSee($unread->name)
            ->assertSee($read->name)
            ->assertDontSee($handled->name);
    }

    public function test_opening_an_enquiry_marks_it_read(): void
    {
        $user = User::factory()->create();
        $enquiry = Enquiry::factory()->create();

        $this->assertTrue($enquiry->isUnread());

        $this->actingAs($user)->get(route('enquiries.show', $enquiry))
            ->assertOk()
            ->assertSee($enquiry->message);

        $this->assertFalse($enquiry->refresh()->isUnread());
    }

    public function test_reading_again_does_not_move_the_read_timestamp(): void
    {
        $user = User::factory()->create();
        $enquiry = Enquiry::factory()->read()->create();
        $firstRead = $enquiry->read_at;

        $this->actingAs($user)->get(route('enquiries.show', $enquiry));

        $this->assertTrue($firstRead->equalTo($enquiry->refresh()->read_at));
    }

    public function test_an_enquiry_can_be_marked_handled_and_reopened(): void
    {
        $user = User::factory()->create();
        $enquiry = Enquiry::factory()->create();

        $this->actingAs($user)->patch(route('enquiries.handled', $enquiry))->assertRedirect();
        $this->assertTrue($enquiry->refresh()->isHandled());
        $this->assertSame('handled', $enquiry->displayStatus());

        $this->actingAs($user)->patch(route('enquiries.handled', $enquiry))->assertRedirect();
        $this->assertFalse($enquiry->refresh()->isHandled());
        // Reopened, but it stays read - it was already seen.
        $this->assertSame('open', $enquiry->displayStatus());
    }

    public function test_an_enquiry_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $enquiry = Enquiry::factory()->create();

        $this->actingAs($user)->delete(route('enquiries.destroy', $enquiry))
            ->assertRedirect(route('enquiries.index'));

        $this->assertSame(0, Enquiry::count());
    }

    public function test_the_sidebar_badges_the_unread_count(): void
    {
        $user = User::factory()->create();
        Enquiry::factory()->count(3)->create();
        Enquiry::factory()->read()->create();

        $this->assertSame(3, Enquiry::unreadCount());

        $this->actingAs($user)->get(route('enquiries.index'))
            ->assertOk()
            ->assertSee('Enquiries');
    }

    public function test_the_inbox_is_staff_only(): void
    {
        $enquiry = Enquiry::factory()->create();

        $this->get(route('enquiries.index'))->assertRedirect(route('login'));
        $this->get(route('enquiries.show', $enquiry))->assertRedirect(route('login'));
        $this->patch(route('enquiries.handled', $enquiry))->assertRedirect(route('login'));
        $this->delete(route('enquiries.destroy', $enquiry))->assertRedirect(route('login'));

        $this->assertSame(1, Enquiry::count());
        $this->assertTrue($enquiry->refresh()->isUnread());
    }
}
