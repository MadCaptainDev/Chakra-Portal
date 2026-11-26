<?php

namespace Tests\Feature;

use App\Http\Middleware\SendShortsSummaryDaily;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\VentureUpdate;
use App\Support\WorkReport;
use App\Support\YoutubeShorts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The YouTube Short duty: every reel posted on Instagram goes up on YouTube
 * (same video, no copyrighted music). Not counted as content -- marked by
 * whoever posts it, and the admins hear daily about what is missing.
 */
class YoutubeShortsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $gokul;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-02 11:00');
        $this->withoutVite();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->gokul = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function posted(string $title, string $when, string $kind = 'reel'): Todo
    {
        $t = new Todo;
        $t->forceFill(['user_id' => $this->gokul->id, 'title' => $title, 'venture' => 'Janet', 'status' => Todo::STATUS_COMPLETED,
            'kind' => $kind, 'posted_at' => Carbon::parse($when), 'starts_on' => today(), 'due_on' => today()])->save();

        return $t;
    }

    public function test_missed_shorts_are_listed_per_editor_and_summarised_to_admins(): void
    {
        foreach (['2026-11-28 18:00', '2026-11-29 18:00', '2026-11-30 18:00', '2026-12-01 09:00'] as $i => $at) {
            $this->posted('Reel '.$i, $at);
        }
        $this->posted('Just posted', '2026-12-02 09:00');            // inside the day's grace
        $this->posted('Only delivered', '2026-11-28 10:00', 'delivered'); // no YouTube duty

        $this->assertSame(['Gokul' => 4], YoutubeShorts::missingByEditor()->map->count()->all());
        $this->assertCount(5, YoutubeShorts::due($this->gokul)); // his own list includes the fresh one

        Notification::fake();
        $this->assertTrue(SendShortsSummaryDaily::send());
        Notification::assertSentTo($this->admin, VentureUpdate::class, fn (VentureUpdate $n) => $n->title === '▶ 4 YouTube Shorts not posted'
            && str_contains($n->body, 'Gokul 4') && str_contains($n->body, 'Reel 0'));
        Notification::assertNotSentTo($this->gokul, VentureUpdate::class);
    }

    public function test_marking_posted_on_youtube_records_who_and_only_an_admin_undoes_it_later(): void
    {
        $reel = $this->posted('Janet - Diwali', '2026-11-30 18:00');
        $delivered = $this->posted('Client copy', '2026-11-30 18:00', 'delivered');

        $this->actingAs($this->gokul)->postJson(route('my.editing.act', $delivered), ['action' => 'youtube'])->assertStatus(422);
        $this->actingAs($this->gokul)->postJson(route('my.editing.act', $reel), ['action' => 'youtube'])->assertOk()->assertJsonPath('youtube.by', 'Gokul');
        $this->assertSame($this->gokul->id, $reel->fresh()->youtube_posted_by_id);
        $this->assertCount(0, YoutubeShorts::due());

        Carbon::setTestNow(now()->addHours(2));
        $this->actingAs($this->gokul)->postJson(route('my.editing.act', $reel), ['action' => 'unyoutube'])->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('my.editing.act', $reel), ['action' => 'unyoutube'])->assertOk();
        $this->assertNull($reel->fresh()->youtube_posted_at);
    }

    public function test_the_lists_and_the_work_report_show_it(): void
    {
        $late = $this->posted('Late one', '2026-11-30 08:00'); // this week (Mon 30 – Sun 6)
        $late->forceFill(['youtube_posted_at' => Carbon::parse('2026-12-01 10:00'), 'youtube_posted_by_id' => $this->gokul->id])->save();
        $this->posted('Missing one', '2026-11-30 18:00');

        $this->actingAs($this->gokul)->get(route('my.editing'))->assertOk()->assertSee('YouTube Shorts to post')->assertSee('Missing one');
        $this->actingAs($this->admin)->get(route('editor-updates'))->assertOk()->assertSee('YouTube Shorts to post')->assertSee('Gokul');

        $row = WorkReport::for('week', '2026-12-02')->shorts()->first();
        $this->assertSame(['on_time' => 0, 'late' => 1, 'missing' => 1], array_intersect_key($row, array_flip(['on_time', 'late', 'missing'])));
        $this->actingAs($this->admin)->get(route('work-report', ['period' => 'week']))->assertOk()->assertSee('YouTube Shorts')->assertSee('missing 1');
    }
public function test_reels_posted_before_the_mark_existed_are_never_missing(): void
    {
        $this->posted('Old reel', '2026-11-20 18:00');
        $this->assertCount(0, YoutubeShorts::due());
        $this->assertNull(YoutubeShorts::summary());
    }
}
