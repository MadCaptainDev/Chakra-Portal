<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\VideoCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoCheckTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        $user = User::factory()->employee()->create();
        UserPermission::create(['user_id' => $user->id, 'module' => 'video-check', 'ability' => 'view']);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'SVA kurti reel v3',
            'file_name' => 'sva-kurti-v3.mp4',
            'file_size' => 48_000_000,
            'preset' => 'reel',
            'verdict' => 'warn',
            'results' => [
                ['status' => 'warn', 'title' => 'Quiet (-19 LUFS)', 'detail' => 'Raise it by about 5 dB.'],
                ['status' => 'pass', 'title' => 'Shape 9:16', 'detail' => 'Right shape.'],
            ],
        ], $overrides);
    }

    public function test_an_employee_needs_the_module_to_open_it(): void
    {
        $this->actingAs(User::factory()->employee()->create())->get('/video-check')->assertForbidden();
        $this->actingAs($this->editor())->get('/video-check')->assertOk()->assertSee('Video Checker');
    }

    public function test_an_admin_can_open_it_and_it_is_in_the_menu(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/video-check')
            ->assertOk()
            ->assertSee(route('video-check.index'), false);
    }

    public function test_saving_keeps_the_verdict_and_nothing_else(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->postJson('/video-check', $this->payload())->assertCreated();

        $check = VideoCheck::sole();
        $this->assertSame($editor->id, $check->user_id);
        $this->assertSame('warn', $check->verdict);
        $this->assertSame(['warn' => 1], $check->tally());
    }

    public function test_junk_is_refused(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->postJson('/video-check', $this->payload(['preset' => 'tiktok']))->assertUnprocessable();
        $this->actingAs($editor)->postJson('/video-check', $this->payload(['verdict' => 'great']))->assertUnprocessable();
        $this->actingAs($editor)->postJson('/video-check', $this->payload(['results' => [['status' => 'nope', 'title' => 'x']]]))->assertUnprocessable();

        $this->assertSame(0, VideoCheck::count());
    }

    public function test_staff_see_their_own_history_and_admins_see_everyones(): void
    {
        $mine = $this->editor();
        $theirs = $this->editor();

        VideoCheck::create($this->payload(['label' => 'Mine', 'user_id' => $mine->id]));
        VideoCheck::create($this->payload(['label' => 'Theirs', 'user_id' => $theirs->id]));

        $this->actingAs($mine)->get('/video-check')->assertSee('Mine')->assertDontSee('Theirs');
        $this->actingAs(User::factory()->create())->get('/video-check')->assertSee('Mine')->assertSee('Theirs');
    }
}
