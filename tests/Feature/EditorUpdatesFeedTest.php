<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The JSON feed behind the Editor Updates live pane. */
class EditorUpdatesFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_todays_moves_newest_first_with_counts(): void
    {
        $sanjai = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $this->travelTo(today()->setTime(10, 0));
        $id = $this->actingAs($sanjai)->postJson(route('my.editing.store'), ['title' => 'SVA - Cotton Frock'])->json('card.id');
        $this->travelTo(today()->setTime(10, 30));
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $this->travelTo(today()->setTime(11, 0));
        $this->postJson(route('my.editing.correct', $id), ['title' => 'Logo cut']);

        Artisan::call('editor-updates:feed');
        $feed = json_decode(Artisan::output(), true);

        $this->assertSame(['Correction', 'Started', 'Added'], array_column($feed['moves'], 'label'));
        $this->assertSame('Logo cut', $feed['moves'][0]['detail']);
        $this->assertSame('SVA - Cotton Frock', $feed['moves'][0]['title']);
        $this->assertSame(['added' => 1, 'fixes' => 1, 'done' => 0, 'posted' => 0], $feed['totals']);
        $this->assertSame('Sanjai', $feed['people'][0]['person']);
        $this->assertSame(['SVA - Cotton Frock'], $feed['people'][0]['working']);
        $this->assertSame('working', $feed['people'][0]['state']);
        $this->assertSame('Correction', $feed['people'][0]['last_label']);
    }
}
