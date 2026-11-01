<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\ReelTranscript;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Tools\Content\TranscribeReel;
use App\Tools\ToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReelTranscriberTest extends TestCase
{
    use RefreshDatabase;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        AiSetting::current()->forceFill(['api_key' => 'gsk_test', 'provider' => AiSetting::PROVIDER_GROQ])->save();
        $this->writer = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->writer->syncPermissions(['scripts' => ['view']]);
        $this->writer->refresh();

        Http::fake([
            'graph.instagram.com/*' => Http::response(['media_url' => 'https://cdn.example/reel.mp4']),
            'api.groq.com/openai/v1/audio/transcriptions' => Http::response(['text' => 'ஸ்டெப் கட் நல்லா இருக்கும்', 'language' => 'Tamil', 'duration' => 37.4]),
            'api.groq.com/openai/v1/audio/translations' => Http::response(['text' => 'Step cut will look good']),
            'api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => "Step cut nalla irukkum.\n---\nWhich haircuts suit which face."]]]]),
        ]);
    }

    private function clientReel(): SocialMediaItem
    {
        $client = Client::create(['name' => 'Riya', 'phone' => '9000000001']);
        $account = (new SocialAccount)->forceFill(['client_id' => $client->id, 'platform' => 'instagram', 'platform_user_id' => '1', 'access_token' => 't', 'status' => 'connected']);
        $account->save();

        return SocialMediaItem::create(['social_account_id' => $account->id, 'platform_media_id' => 'm1', 'permalink' => 'https://www.instagram.com/reel/ABC123/', 'media_product_type' => 'REELS', 'caption' => 'Haircuts!']);
    }

    public function test_a_client_reel_link_is_transcribed_translated_and_cleaned_up_once(): void
    {
        $media = $this->clientReel();

        $out = (new TranscribeReel)->handle(['url' => 'https://www.instagram.com/reel/ABC123/?igsh=xyz'], $this->writer);

        $this->assertSame('Tamil', $out['language']);
        $this->assertSame(37, $out['seconds']);
        $this->assertSame('Step cut nalla irukkum.', $out['tanglish']);
        $this->assertSame('Which haircuts suit which face.', $out['summary']);
        $this->assertSame('Step cut will look good', $out['english']);
        $this->assertSame($media->id, ReelTranscript::sole()->social_media_item_id);
        // Groq was given Instagram's fresh video link to fetch itself.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'audio/transcriptions') && str_contains($r->body(), 'https://cdn.example/reel.mp4'));

        // Asking again is answered from what was kept.
        $sent = count(Http::recorded());
        (new TranscribeReel)->handle(['url' => 'https://www.instagram.com/reel/ABC123/'], $this->writer);
        $this->assertCount($sent, Http::recorded());
    }

    public function test_someone_elses_reel_says_to_upload_it(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('upload');
        (new TranscribeReel)->handle(['url' => 'https://www.instagram.com/reel/NOTOURS/'], $this->writer);
    }

    public function test_the_page_takes_an_uploaded_video_and_shows_the_result(): void
    {
        $this->actingAs($this->writer)->get(route('reel-transcripts.index'))->assertOk()->assertSee('Reel Transcriber');

        $response = $this->post(route('reel-transcripts.store'), ['video' => UploadedFile::fake()->create('reel.mp4', 2048, 'video/mp4')]);
        $t = ReelTranscript::sole();
        $response->assertRedirect(route('reel-transcripts.show', $t));
        $this->get(route('reel-transcripts.show', $t))->assertOk()->assertSee('Step cut nalla irukkum.')->assertSee('Which haircuts suit which face.');

        // Without scripts access, no page.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))->get(route('reel-transcripts.index'))->assertForbidden();
    }
}
