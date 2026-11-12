<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Notifications\TomorrowShootsReport;
use App\Support\ShootReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The night-before check: which of tomorrow's shoots are not ready. */
class ShootReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shoots.require_kit' => true]);
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(18, 0));
    }

    private function shoot(array $attrs = [], ?array $notion = null): Shoot
    {
        $ns = $notion !== null ? NotionShoot::factory()->create($notion) : null;

        return Shoot::create(array_merge([
            'title' => 'Squad Architects', 'starts_at' => now()->addDay()->setTime(10, 0),
            'status' => Shoot::STATUS_CONFIRMED, 'location' => 'Trichy', 'notion_shoot_id' => $ns?->id,
        ], $attrs));
    }

    private function ready(Shoot $shoot): Shoot
    {
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => User::factory()->create()->id, 'role' => 'Camera']);
        $item = EquipmentItem::create(['name' => 'FX30', 'quantity' => 1, 'is_active' => true, 'status' => 'available']);
        $shoot->kits()->create(['equipment_item_id' => $item->id, 'quantity' => 1]);

        return $shoot;
    }

    public function test_notion_confirmation_and_content_decide_readiness(): void
    {
        $fine = $this->ready($this->shoot([], ['client_confirmed' => 'Yes', 'content_ready' => 'Ready']));
        $bad = $this->shoot(['location' => null, 'starts_at' => now()->addDay()->startOfDay()], ['client_confirmed' => 'No', 'content_ready' => 'Not Yet', 'location' => null]);

        $this->assertSame([], ShootReadiness::problems($fine));
        $this->assertSame(
            ['unconfirmed', 'content', 'crew', 'kit', 'location', 'time'],
            array_column(ShootReadiness::problems($bad), 'key'),
        );
    }

    public function test_a_portal_shoot_still_planned_is_not_confirmed(): void
    {
        $shoot = $this->ready($this->shoot(['status' => Shoot::STATUS_PLANNED]));

        $this->assertContains('unconfirmed', array_column(ShootReadiness::problems($shoot), 'key'));
    }

    public function test_admins_hear_at_six_which_shoots_are_not_ready_once_a_day(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->ready($this->shoot(['title' => 'Janet reels'], ['client_confirmed' => 'Yes', 'content_ready' => 'Ready']));
        $this->shoot(['title' => 'Riya bridal'], ['client_confirmed' => 'No', 'content_ready' => 'Not Yet']);
        $this->shoot(['title' => 'Next week', 'starts_at' => now()->addDays(5)]);

        $this->artisan('shoots:tomorrow-check')->assertSuccessful();
        $this->artisan('shoots:tomorrow-check')->assertSuccessful();

        Notification::assertSentToTimes($admin, TomorrowShootsReport::class, 1);
        Notification::assertSentTo($admin, function (TomorrowShootsReport $n) use ($admin) {
            $m = $n->toFcm($admin);

            return $m->title === 'Tomorrow: 1 of 2 shoots not ready'
                && str_contains($m->body, '⚠ 10:00 AM Riya bridal: Client not confirmed, Content not ready, No crew, No kit listed')
                && str_contains($m->body, '✓ 10:00 AM Janet reels')
                && ! str_contains($m->body, 'Next week');
        });
    }

    public function test_the_dashboard_and_shoot_page_say_so(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $shoot = $this->shoot(['title' => 'Riya bridal'], ['client_confirmed' => 'No']);
        $this->withoutVite();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('1 of tomorrow&#039;s shoot not ready', false);
        $this->get(route('shoots.show', $shoot))->assertOk()->assertSee('Not ready yet:')->assertSee('Client not confirmed');
    }

    public function test_a_blank_notion_confirmation_is_a_softer_warning(): void
    {
        $shoot = $this->ready($this->shoot([], ['client_confirmed' => null, 'content_ready' => 'Ready']));

        $this->assertSame([['key' => 'unconfirmed', 'tone' => 'amber', 'label' => 'Confirmation not marked in Notion']], ShootReadiness::problems($shoot));
    }
}
