<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShootIndexTest extends TestCase
{
    use RefreshDatabase;

    private function planner(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['shoots' => ['view', 'create', 'edit', 'delete']]);

        return $user->refresh();
    }

    private function shoot(array $overrides = []): Shoot
    {
        return Shoot::create($overrides + [
            'title' => 'Tea montage',
            'starts_at' => Carbon::parse('2026-08-28 09:00'),
            'status' => Shoot::STATUS_PLANNED,
        ]);
    }

    public function test_the_index_is_a_status_board_not_a_flat_list(): void
    {
        Carbon::setTestNow('2026-08-24 10:00');

        $client = Client::factory()->create(['name' => 'SVA Silks']);
        $planned = $this->shoot([
            'title' => 'Morning bridal',
            'client_id' => $client->id,
            'status' => Shoot::STATUS_PLANNED,
            'starts_at' => Carbon::parse('2026-08-26 09:00'),
        ]);
        $confirmed = $this->shoot([
            'title' => 'Store opening',
            'status' => Shoot::STATUS_CONFIRMED,
            'starts_at' => Carbon::parse('2026-08-27 11:00'),
        ]);
        // Past by default — stays off the upcoming board.
        $this->shoot([
            'title' => 'Last week wrap',
            'status' => Shoot::STATUS_COMPLETED,
            'starts_at' => Carbon::parse('2026-08-10 09:00'),
        ]);

        $response = $this->actingAs($this->planner())->get(route('shoots.index'));

        $response->assertOk();
        $response->assertSeeInOrder(['Planned', 'Confirmed', 'Completed', 'Cancelled']);
        $response->assertSee('Morning bridal');
        $response->assertSee('Store opening');
        $response->assertDontSee('Last week wrap');
        $response->assertSee(route('shoots.show', $planned), false);
        $response->assertSee(route('shoots.show', $confirmed), false);
        $response->assertViewHas('columns', function ($columns) use ($planned, $confirmed) {
            return $columns->keys()->all() === array_keys(Shoot::STATUSES)
                && $columns['planned']['shoots']->contains('id', $planned->id)
                && $columns['confirmed']['shoots']->contains('id', $confirmed->id)
                && $columns['completed']['shoots']->isEmpty();
        });
    }

    public function test_a_status_filter_keeps_a_single_board_column(): void
    {
        Carbon::setTestNow('2026-08-24 10:00');

        $this->shoot(['title' => 'Only planned', 'status' => Shoot::STATUS_PLANNED]);
        $this->shoot(['title' => 'Already on', 'status' => Shoot::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->planner())
            ->get(route('shoots.index', ['status' => Shoot::STATUS_PLANNED]));

        $response->assertOk();
        $response->assertSee('Only planned');
        $response->assertDontSee('Already on');
        $response->assertViewHas('columns', fn ($columns) => $columns->keys()->all() === [Shoot::STATUS_PLANNED]);
    }
}
