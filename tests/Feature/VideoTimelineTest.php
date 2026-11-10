<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Models\Video;
use App\Services\ShootRun;
use App\Services\Videos\VideoThumbnail;
use App\Services\Videos\VideoTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One video's story from shoot to post, and its small Instagram picture. */
class VideoTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function reel(array $attributes = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'source' => ContentItem::SOURCE_REEL,
            'notion_page_id' => 'page-'.uniqid(),
            'title' => 'Sage green saree reel',
            'venture' => 'SVA Silks',
            'status' => 'To Be Edited',
        ], $attributes));
    }

    private function media(array $attributes = []): SocialMediaItem
    {
        $account = SocialAccount::create([
            'client_id' => Client::factory()->create()->id,
            'platform' => 'instagram',
            'platform_account_id' => 'ig-'.uniqid(),
            'username' => 'sva',
            'status' => 'active',
        ]);

        $media = new SocialMediaItem;
        $media->forceFill(array_merge([
            'social_account_id' => $account->id,
            'platform_media_id' => 'media-'.uniqid(),
            'media_type' => 'VIDEO',
            'media_product_type' => 'REELS',
            'permalink' => 'https://www.instagram.com/reel/DLx3abcQ9z/',
            'thumbnail_url' => 'https://cdn.example.test/thumb.jpg',
            'media_url' => 'https://cdn.example.test/video.mp4',
            'posted_at' => now(),
        ], $attributes))->save();

        return $media;
    }

    /** A real JPEG, 1080x1920, the size Instagram hands back. */
    private function jpeg(): string
    {
        $image = imagecreatetruecolor(1080, 1920);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    protected function tearDown(): void
    {
        foreach (glob(public_path('uploads/video-thumbs/*.webp')) ?: [] as $file) {
            if (filemtime($file) >= time() - 120 && str_contains(file_get_contents($file, false, null, 0, 16), 'WEBP')) {
                // Only the files this run wrote: named after ids that exist only in the test database.
                if (in_array($file, $this->written, true)) {
                    @unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    /** @var list<string> */
    private array $written = [];

    public function test_a_published_videos_thumbnail_is_saved_small_and_local(): void
    {
        Http::fake(['cdn.example.test/thumb.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        $item = $this->reel(['status' => 'Published']);
        $video = Video::create(['content_item_id' => $item->id, 'social_media_item_id' => $this->media()->id]);

        $path = VideoThumbnail::store($video);
        $this->written[] = public_path((string) $path);

        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/video-thumbs/', $path);
        $this->assertSame($path, $video->fresh()->thumbnail_path);
        [$width, $height] = getimagesize(public_path($path));
        $this->assertSame(160, $width);
        $this->assertSame(284, $height);
        $this->assertLessThan(15 * 1024, filesize(public_path($path)), 'The thumbnail should be a few kilobytes.');

        // Asking again downloads nothing.
        VideoThumbnail::store($video->fresh());
        Http::assertSentCount(1);
    }

    public function test_a_post_link_from_my_editing_finds_its_instagram_post(): void
    {
        Http::fake(['cdn.example.test/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        $media = $this->media();
        $item = $this->reel(['status' => 'Published']);
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $todo = new Todo;
        $todo->forceFill([
            'user_id' => $editor->id, 'title' => 'Sage green saree reel', 'status' => Todo::STATUS_COMPLETED,
            'content_item_id' => $item->id, 'posted_at' => now(), 'starts_on' => today(), 'due_on' => today(),
            'post_url' => 'https://www.instagram.com/reel/DLx3abcQ9z/?igsh=abc',
        ])->save();
        $video = Video::create(['content_item_id' => $item->id, 'todo_id' => $todo->id]);

        $this->assertSame(1, VideoThumbnail::backfill());
        $this->written[] = public_path((string) $video->fresh()->thumbnail_path);

        $this->assertSame($media->id, $video->fresh()->social_media_item_id);
        $this->assertNotNull($video->fresh()->thumbnail_path);
    }

    public function test_a_shortcode_is_read_from_the_usual_instagram_links(): void
    {
        $this->assertSame('DLx3abcQ9z', VideoThumbnail::shortcode('https://www.instagram.com/reel/DLx3abcQ9z/?igsh=1'));
        $this->assertSame('DLx3abcQ9z', VideoThumbnail::shortcode('https://instagram.com/svasilks/p/DLx3abcQ9z'));
        $this->assertNull(VideoThumbnail::shortcode('https://drive.google.com/file/d/abc'));
        $this->assertNull(VideoThumbnail::shortcode(null));
    }

    public function test_an_expired_link_or_a_video_file_saves_nothing_and_does_not_throw(): void
    {
        Http::fake(['*' => Http::response('gone', 403)]);
        $item = $this->reel();
        $video = Video::create(['content_item_id' => $item->id, 'social_media_item_id' => $this->media(['thumbnail_url' => null])->id]);

        $this->assertNull(VideoThumbnail::store($video));
        $this->assertNull($video->fresh()->thumbnail_path);
    }

    public function test_the_timeline_reads_shoot_editing_planner_and_post_in_order(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 0));
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Aron']);
        $notionShoot = NotionShoot::factory()->create();
        $shoot = Shoot::create(['title' => 'SVA Shoot', 'starts_at' => now(), 'status' => Shoot::STATUS_CONFIRMED, 'notion_shoot_id' => $notionShoot->id]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crew->id, 'role' => 'Camera']);
        $item = $this->reel(['notion_shoot_id' => $notionShoot->id, 'notion_created_at' => now()->subDays(3)]);

        ShootRun::start($shoot, $crew);
        $this->travel(20)->minutes();
        $clip = ShootRun::recordVideo($shoot->fresh(), $crew, 'Sage green take');
        $this->travel(20)->minutes();
        ShootRun::finish($shoot->fresh(), $crew);
        ShootRun::matchClip($shoot->fresh(), $clip, $item->id);

        $this->travel(1)->days();
        $todo = new Todo;
        $todo->forceFill(['user_id' => $editor->id, 'title' => $item->title, 'status' => Todo::STATUS_WAITING, 'content_item_id' => $item->id, 'starts_on' => today(), 'due_on' => today()])->save();
        TodoUpdate::record($todo, $editor, TodoUpdate::CREATED);
        TodoUpdate::record($todo, $editor, TodoUpdate::STATUS, ['from_status' => Todo::STATUS_WAITING, 'to_status' => Todo::STATUS_STARTED]);
        $this->travel(2)->hours();
        TodoUpdate::record($todo, $editor, TodoUpdate::STATUS, ['from_status' => Todo::STATUS_STARTED, 'to_status' => Todo::STATUS_COMPLETED]);
        $this->travel(1)->days();
        ContentItemChange::record($item, null, 'status', 'To Be Edited', 'Published', 'notion');

        $story = VideoTimeline::forItem($item->fresh());

        $this->assertSame(
            ['Added to the planner', 'Shoot started', 'Filmed', 'Shoot wrapped', 'Editing card added', 'Editing started', 'Edit done, sent for review', 'Status: Published'],
            $story['events']->pluck('label')->all(),
        );
        $this->assertSame('Aron', $story['events']->firstWhere('label', 'Filmed')['by']);
        $this->assertSame('In Notion', $story['events']->last()['by']);

        // From an editing card, the same story.
        $this->assertSame($story['events']->count(), VideoTimeline::forTodo($todo)['events']->count());
    }

    public function test_the_video_page_opens_for_admins_and_the_cards_editor_only(): void
    {
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $todo = new Todo;
        $todo->forceFill(['user_id' => $editor->id, 'title' => 'Launch reel', 'status' => Todo::STATUS_STARTED, 'starts_on' => today(), 'due_on' => today()])->save();
        TodoUpdate::record($todo, $editor, TodoUpdate::CREATED);

        $this->actingAs($admin)->get(route('videos.card', $todo))->assertOk()->assertSee('Video timeline')->assertSee('Launch reel')->assertSee('Editing card added');
        $this->actingAs($editor)->get(route('videos.card', $todo))->assertOk();
        $this->actingAs($other)->get(route('videos.card', $todo))->assertForbidden();
    }

    public function test_a_clip_can_only_be_matched_to_a_reel_planned_for_its_shoot(): void
    {
        $crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $notionShoot = NotionShoot::factory()->create();
        $shoot = Shoot::create(['title' => 'Shoot', 'starts_at' => now(), 'status' => Shoot::STATUS_CONFIRMED, 'notion_shoot_id' => $notionShoot->id]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crew->id, 'role' => 'Camera']);
        $mine = $this->reel(['notion_shoot_id' => $notionShoot->id]);
        $elsewhere = $this->reel(['title' => 'Another shoot reel']);
        ShootRun::start($shoot, $crew);
        $clip = ShootRun::recordVideo($shoot->fresh(), $crew, 'Take one');
        ShootRun::finish($shoot->fresh(), $crew);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))->assertSee('Videos in the planner');
        $this->actingAs($crew)->post(route('my.shoots.videos.match', [$shoot, $clip]), ['content_item_id' => $elsewhere->id])->assertSessionHas('error');
        $this->actingAs($crew)->post(route('my.shoots.videos.match', [$shoot, $clip]), ['content_item_id' => $mine->id])->assertSessionMissing('error');

        $this->assertSame($clip->id, Video::where('content_item_id', $mine->id)->value('shoot_video_id'));

        // "Not in planner" takes the link away again.
        $this->actingAs($crew)->post(route('my.shoots.videos.match', [$shoot, $clip]), ['content_item_id' => ''])->assertSessionMissing('error');
        $this->assertNull(Video::where('content_item_id', $mine->id)->value('shoot_video_id'));
    }
}
