<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\User;
use App\Support\CrewPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CrewPortalTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $phone, array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'role' => User::ROLE_EMPLOYEE,
            'phone' => $phone,
        ]);
    }

    private function shoot(array $overrides = []): Shoot
    {
        return Shoot::create($overrides + [
            'title' => 'Tea montage',
            'starts_at' => Carbon::parse('2026-08-14 09:00'),
            'status' => Shoot::STATUS_PLANNED,
        ]);
    }

    // ——— Who a number belongs to ———

    public function test_a_staff_number_matches_however_it_was_typed(): void
    {
        $user = $this->staff('9786579573');

        // What Meta actually sends: country code, no punctuation.
        $this->assertSame($user->id, User::findForWhatsappCrew('919786579573')?->id);
    }

    public function test_a_number_stored_with_spaces_and_a_plus_still_matches(): void
    {
        $user = $this->staff('+91 6380240378');

        $this->assertSame($user->id, User::findForWhatsappCrew('916380240378')?->id);
    }

    public function test_an_unknown_number_matches_nobody(): void
    {
        $this->staff('9786579573');

        $this->assertNull(User::findForWhatsappCrew('919999000011'));
    }

    /**
     * The one that matters: a client texting in must never be handed the
     * studio's own schedule.
     */
    public function test_a_client_login_is_never_treated_as_crew(): void
    {
        $client = Client::factory()->create();
        User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'phone' => '9786579573',
        ]);

        $this->assertNull(User::findForWhatsappCrew('919786579573'));
    }

    public function test_a_staff_member_with_no_phone_is_never_matched_by_a_blank_number(): void
    {
        $this->staff('');

        $this->assertNull(User::findForWhatsappCrew(''));
    }

    // ——— Their shoots ———

    public function test_only_their_own_shoots_are_listed(): void
    {
        $user = $this->staff('9786579573');
        $other = $this->staff('9000000001');

        $mine = $this->shoot(['title' => 'Mine', 'starts_at' => now()->addDays(2)->setTime(9, 0)]);
        $theirs = $this->shoot(['title' => 'Theirs', 'starts_at' => now()->addDays(3)->setTime(9, 0)]);

        $mine->crew()->create(['user_id' => $user->id, 'role' => 'Camera']);
        $theirs->crew()->create(['user_id' => $other->id, 'role' => 'Sound']);

        $reply = CrewPortal::myShoots($user);

        $this->assertStringContainsString('Mine', $reply);
        $this->assertStringNotContainsString('Theirs', $reply);
    }

    public function test_a_shoot_synced_without_a_time_says_so_rather_than_claiming_midnight(): void
    {
        $user = $this->staff('9786579573');

        // How every Notion-synced shoot lands: a date, no time on it.
        $shoot = $this->shoot(['starts_at' => now()->addDays(2)->startOfDay()]);
        $shoot->crew()->create(['user_id' => $user->id]);

        $reply = CrewPortal::myShoots($user);

        $this->assertStringContainsString('time TBC', $reply);
        $this->assertStringNotContainsString('12:00 AM', $reply);
    }

    public function test_a_personal_call_time_beats_the_shoots_own_start(): void
    {
        $user = $this->staff('9786579573');

        $shoot = $this->shoot(['starts_at' => now()->addDays(2)->setTime(9, 0)]);
        $shoot->crew()->create(['user_id' => $user->id, 'call_time' => '07:30']);

        $this->assertStringContainsString('7:30 AM', CrewPortal::myShoots($user));
    }

    public function test_past_shoots_are_not_offered(): void
    {
        $user = $this->staff('9786579573');

        $old = $this->shoot(['title' => 'Last week', 'starts_at' => now()->subDays(7)]);
        $old->crew()->create(['user_id' => $user->id]);

        $this->assertStringContainsString("not crewed on anything coming up", CrewPortal::myShoots($user));
    }

    // ——— Confirming ———

    public function test_confirming_marks_the_soonest_unconfirmed_shoot(): void
    {
        $user = $this->staff('9786579573');

        $soon = $this->shoot(['title' => 'Soon', 'starts_at' => now()->addDays(1)->setTime(9, 0)]);
        $later = $this->shoot(['title' => 'Later', 'starts_at' => now()->addDays(5)->setTime(9, 0)]);

        $soonRow = $soon->crew()->create(['user_id' => $user->id]);
        $laterRow = $later->crew()->create(['user_id' => $user->id]);

        $reply = CrewPortal::confirmNextShoot($user);

        $this->assertStringContainsString('Soon', $reply);
        $this->assertNotNull($soonRow->refresh()->confirmed_at);
        $this->assertNull($laterRow->refresh()->confirmed_at);
    }

    public function test_confirming_again_moves_on_and_then_says_there_is_nothing_left(): void
    {
        $user = $this->staff('9786579573');

        $one = $this->shoot(['title' => 'One', 'starts_at' => now()->addDays(1)->setTime(9, 0)]);
        $one->crew()->create(['user_id' => $user->id]);

        CrewPortal::confirmNextShoot($user);

        $this->assertStringContainsString('already confirmed', CrewPortal::confirmNextShoot($user));
    }

    public function test_confirming_with_nothing_scheduled_is_not_an_error(): void
    {
        $user = $this->staff('9786579573');

        $this->assertStringContainsString('nothing to confirm', CrewPortal::confirmNextShoot($user));
    }

    // ——— Flagging kit ———

    public function test_flagging_marks_the_item_and_says_which_one(): void
    {
        $user = $this->staff('9786579573');
        $item = EquipmentItem::create(['name' => 'Tripod', 'quantity' => 2]);

        $reply = CrewPortal::flagKit($user, 'the tripod is broken');

        $this->assertStringContainsString('Tripod', $reply);
        $this->assertSame(EquipmentItem::STATUS_DAMAGED, $item->refresh()->status);
        $this->assertStringContainsString($user->name, (string) $item->status_note);
    }

    public function test_a_message_matching_two_items_changes_nothing_and_asks(): void
    {
        $user = $this->staff('9786579573');
        $a = EquipmentItem::create(['name' => 'A6400 Battery', 'quantity' => 3]);
        $b = EquipmentItem::create(['name' => 'A7 Battery', 'quantity' => 2]);

        $reply = CrewPortal::flagKit($user, 'battery is broken');

        $this->assertStringContainsString('more than one', $reply);
        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $a->refresh()->status);
        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $b->refresh()->status);
    }

    /**
     * "Sony A7 M5" is a substring of nothing else here, but the exact-match
     * rule is what stops a name that IS contained in a longer one (a body vs
     * its charger) from being unflaggable.
     */
    public function test_an_exact_name_wins_over_a_longer_one_containing_it(): void
    {
        $user = $this->staff('9786579573');
        $body = EquipmentItem::create(['name' => 'Sony A7 M5', 'quantity' => 1]);
        EquipmentItem::create(['name' => 'Sony A7 M5 Charger', 'quantity' => 1]);

        $reply = CrewPortal::flagKit($user, 'sony a7 m5 broken');

        $this->assertStringNotContainsString('more than one', $reply);
        $this->assertSame(EquipmentItem::STATUS_DAMAGED, $body->refresh()->status);
    }

    public function test_a_message_naming_nothing_we_own_changes_nothing(): void
    {
        $user = $this->staff('9786579573');
        $item = EquipmentItem::create(['name' => 'Tripod', 'quantity' => 1]);

        $this->assertStringContainsString("couldn't find", CrewPortal::flagKit($user, 'the drone is broken'));
        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $item->refresh()->status);
    }

    public function test_a_bare_broken_with_no_item_asks_which_one(): void
    {
        $user = $this->staff('9786579573');
        EquipmentItem::create(['name' => 'Tripod', 'quantity' => 1]);

        $this->assertStringContainsString('which item', CrewPortal::flagKit($user, 'broken'));
    }

    public function test_flagging_as_lost_uses_the_lost_status(): void
    {
        $user = $this->staff('9786579573');
        $item = EquipmentItem::create(['name' => 'Tripod', 'quantity' => 1]);

        CrewPortal::flagKit($user, 'tripod missing', EquipmentItem::STATUS_LOST);

        $this->assertSame(EquipmentItem::STATUS_LOST, $item->refresh()->status);
    }

    public function test_a_retired_item_is_never_flagged(): void
    {
        $user = $this->staff('9786579573');
        $item = EquipmentItem::create(['name' => 'Tripod', 'quantity' => 1, 'is_active' => false]);

        $this->assertStringContainsString("couldn't find", CrewPortal::flagKit($user, 'tripod broken'));
        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $item->refresh()->status);
    }
}
