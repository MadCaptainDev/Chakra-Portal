<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\McpToken;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WidgetToken;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function today(string $token)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/widget/today');
    }

    private function log(User $user, int $minutes, ?string $day = null): void
    {
        TimesheetEntry::create([
            'user_id' => $user->id,
            'worked_on' => $day ?? today()->toDateString(),
            'task' => 'Edit',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => $minutes,
        ]);
    }

    public function test_no_key_or_a_bad_key_is_refused(): void
    {
        $this->getJson('/api/widget/today')->assertUnauthorized();
        $this->today('chakrawgt_nope')->assertUnauthorized();
    }

    public function test_an_mcp_token_does_not_open_the_widget_feed(): void
    {
        $user = User::factory()->employee()->create();

        $this->today(McpToken::issue($user, 'Laptop')['plain'])->assertUnauthorized();
    }

    public function test_an_employee_sees_their_own_hours_and_only_their_crewed_shoots(): void
    {
        $user = User::factory()->employee()->create();
        $other = User::factory()->employee()->create();

        $this->log($user, 90);
        $this->log($user, 30);
        $this->log($other, 600);

        $mine = Shoot::create(['title' => 'Mine', 'starts_at' => now()->startOfDay()->addHours(10), 'status' => Shoot::STATUS_CONFIRMED]);
        Shoot::create(['title' => 'Not mine', 'starts_at' => now()->startOfDay()->addHours(11), 'status' => Shoot::STATUS_CONFIRMED]);
        ShootCrew::create(['shoot_id' => $mine->id, 'user_id' => $user->id, 'role' => 'Camera']);

        $data = $this->today(WidgetToken::issue($user, 'iPhone')['plain'])->assertOk()->json();

        $this->assertSame(120, $data['hours']['today_minutes']);
        $this->assertSame('2h', $data['hours']['today_label']);
        $this->assertSame(1, $data['shoots']['count']);
        $this->assertSame('Mine', $data['shoots']['items'][0]['title']);
        $this->assertArrayNotHasKey('reels', $data);
        $this->assertArrayNotHasKey('team_hours', $data);
    }

    public function test_an_admin_gets_team_hours_every_shoot_and_the_reel_planner(): void
    {
        $admin = User::factory()->create();
        $this->log(User::factory()->employee()->create(), 60);

        Shoot::create(['title' => 'A', 'starts_at' => now()->startOfDay()->addHours(9), 'status' => Shoot::STATUS_PLANNED]);
        Shoot::create(['title' => 'Cancelled', 'starts_at' => now()->startOfDay()->addHours(9), 'status' => Shoot::STATUS_CANCELLED]);
        Shoot::create(['title' => 'Tomorrow', 'starts_at' => now()->addDay()->startOfDay()->addHours(9), 'status' => Shoot::STATUS_PLANNED]);

        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'To Be Edited',
            'published_date' => today()->toDateString(),
        ]);

        $data = $this->today(WidgetToken::issue($admin, 'iPhone')['plain'])->assertOk()->json();

        $this->assertSame(60, $data['team_hours']['today_minutes']);
        $this->assertSame(1, $data['shoots']['count']);
        $this->assertSame(1, $data['reels']['total_posting']);
        $this->assertSame(1, $data['reels']['counts']['to_be_edited']);
    }

    public function test_a_revoked_key_stops_working(): void
    {
        $user = User::factory()->employee()->create();
        $issued = WidgetToken::issue($user, 'iPhone');

        $this->actingAs($user)->delete(route('widget-tokens.destroy', $issued['token']))->assertRedirect();

        $this->today($issued['plain'])->assertUnauthorized();
    }

    public function test_the_profile_page_hands_over_a_script_with_the_key_inside(): void
    {
        $user = User::factory()->employee()->create();

        $this->actingAs($user)->post(route('widget-tokens.store'), ['name' => 'My iPhone'])->assertRedirect();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Copy widget script')
            ->assertSee(WidgetToken::PREFIX, false)
            ->assertSee(route('api.widget.today'), false)
            ->assertSee(asset('widget/chakra-widget-app.js'), false);

        // The drawing code the pasted loader downloads is a public file.
        $this->assertFileExists(public_path('widget/chakra-widget-app.js'));
    }

    public function test_nobody_can_revoke_someone_elses_key(): void
    {
        $owner = User::factory()->employee()->create();
        $issued = WidgetToken::issue($owner, 'iPhone');

        $this->actingAs(User::factory()->employee()->create())
            ->delete(route('widget-tokens.destroy', $issued['token']))
            ->assertNotFound();
    }
}
