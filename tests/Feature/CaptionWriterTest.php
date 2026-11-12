<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentCaption;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Ai\CaptionWriter;
use App\Services\Ai\GeminiBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Captions for planner videos: the house format, the rules, the cost. */
class CaptionWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ContentItem $reel;

    protected function setUp(): void
    {
        parent::setUp();
        GeminiBudget::reset();
        $this->withoutVite();
        AiSetting::current()->forceFill(['gemini_api_key' => 'AIza-test', 'gemini_monthly_cap_usd' => 5])->save();

        $this->user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        foreach (['view', 'edit'] as $a) {
            UserPermission::create(['user_id' => $this->user->id, 'module' => 'planner', 'ability' => $a]);
        }

        $client = Client::factory()->create(['name' => 'Janet Hospital']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Janet']);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'Janet']);
        SocialAccount::create(['client_id' => $client->id, 'platform' => 'instagram', 'platform_account_id' => 'ig1', 'username' => 'janethospitaltrichy', 'status' => 'connected']);

        $this->reel = ContentItem::factory()->create([
            'source' => 'reel', 'venture' => 'Janet', 'title' => 'Foods to avoid before pregnancy', 'status' => 'Video Ready',
            'notion_page_id' => 'page-1', 'script' => 'Avoid high mercury fish, processed food and raw milk while planning pregnancy.',
        ]);
    }

    private function option(int $tags = 7, string $angle = 'Benefit'): array
    {
        return [
            'angle' => $angle,
            'hook' => 'Trying to conceive? **Watch what you eat** #fertility',
            'tagline' => '@janethospitaltrichy - Fertility care in Trichy',
            'body' => "1️⃣ High-mercury fish\n2️⃣ Packaged foods #food\n3️⃣ Raw milk",
            'cta' => 'Share this with someone planning a baby ❤️',
            'keywords' => ['pregnancy planning', 'fertility diet', '[foods to avoid]'],
            'hashtags' => array_map(fn ($i) => '#Tag'.$i, range(1, $tags)),
        ];
    }

    private function fakeGemini(int $calls = 0): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['captions' => [$this->option(), $this->option(3, 'Story'), $this->option(5, 'Question')]])]]]]],
            'usageMetadata' => ['promptTokenCount' => 3000, 'candidatesTokenCount' => 900],
        ])]);
    }

    public function test_the_caption_is_put_together_in_the_studio_format_with_the_rules_kept(): void
    {
        $text = CaptionWriter::assemble($this->option(9), 'janethospitaltrichy');
        $lines = explode("\n", $text);

        $this->assertSame('Trying to conceive? Watch what you eat', $lines[0]);
        $this->assertSame('@janethospitaltrichy - Fertility care in Trichy', $lines[1]);
        $this->assertSame(5, CaptionWriter::hashtagCount($text));
        $this->assertStringEndsWith('#Tag1 #Tag2 #Tag3 #Tag4 #Tag5', $text);
        $this->assertStringContainsString('[pregnancy planning, fertility diet, foods to avoid]', $text);
        $this->assertStringNotContainsString('#food', $text);
        $this->assertStringNotContainsString('**', $text);
        $this->assertSame(1, substr_count($text, '@janethospitaltrichy'));
    }

    public function test_a_very_long_caption_is_cut_in_the_body_never_the_handle_or_tags(): void
    {
        $o = $this->option(5);
        $o['body'] = str_repeat('Long line of advice. ', 200);

        $text = CaptionWriter::assemble($o, 'janethospitaltrichy');

        $this->assertLessThanOrEqual(2200, mb_strlen($text));
        $this->assertStringContainsString('@janethospitaltrichy - ', $text);
        $this->assertSame(5, CaptionWriter::hashtagCount($text));
    }

    public function test_three_options_are_written_with_the_note_and_the_clients_handle_and_cost_is_counted(): void
    {
        $this->fakeGemini();

        $this->actingAs($this->user)->postJson(route('planner.data.captions', $this->reel->id), ['note' => 'Mention free consultation this week', 'language' => 'tanglish'])
            ->assertOk()
            ->assertJsonCount(3, 'options')
            ->assertJsonPath('options.0.angle', 'Benefit')
            ->assertJsonPath('reused', false);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'gemini-3.5-flash:generateContent')
            && str_contains($text = $r['contents'][0]['parts'][0]['text'], 'Mention free consultation this week')
            && str_contains($text, '@janethospitaltrichy')
            && str_contains($text, 'Tanglish')
            && str_contains($text, 'at most 5')
            && str_contains($text, 'Avoid high mercury fish'));

        $options = ContentCaption::first()->options;
        $this->assertTrue(collect($options)->every(fn ($o) => CaptionWriter::hashtagCount($o['text']) <= 5));
        $this->assertSame('captions', DB::table('ai_usage')->value('feature'));
        $this->assertGreaterThan(0, GeminiBudget::spentThisMonth());
    }

    public function test_the_same_ask_again_costs_nothing(): void
    {
        $this->fakeGemini();
        $this->actingAs($this->user);

        $this->postJson(route('planner.data.captions', $this->reel->id), ['note' => 'Diwali'])->assertOk();
        $this->postJson(route('planner.data.captions', $this->reel->id), ['note' => 'Diwali'])->assertOk()->assertJsonPath('reused', true);
        Http::assertSentCount(1);

        // Asked for new options on purpose, or with a different note: Gemini again.
        $this->postJson(route('planner.data.captions', $this->reel->id), ['note' => 'Diwali', 'fresh' => true])->assertOk();
        $this->postJson(route('planner.data.captions', $this->reel->id), ['note' => 'Pongal'])->assertOk();
        Http::assertSentCount(3);
    }

    public function test_past_the_monthly_limit_no_caption_is_written(): void
    {
        $this->fakeGemini();
        DB::table('ai_usage')->insert(['provider' => 'gemini', 'model' => 'm', 'cost_usd' => 5, 'created_at' => now()]);

        $this->actingAs($this->user)->postJson(route('planner.data.captions', $this->reel->id))
            ->assertStatus(429)->assertJsonPath('code', 'budget');
        Http::assertNothingSent();
    }

    public function test_a_caption_is_kept_and_more_than_five_hashtags_is_refused(): void
    {
        $this->actingAs($this->user);

        $this->putJson(route('planner.data.caption', $this->reel->id), ['caption' => "Hook\n@janethospitaltrichy - care\n\n#a #b #c #d #e #f"])->assertStatus(422);
        $this->putJson(route('planner.data.caption', $this->reel->id), ['caption' => "Hook\n@janethospitaltrichy - care\n\n#a #b #c"])
            ->assertOk()->assertJsonPath('hashtags', 3);

        $this->assertStringStartsWith('Hook', $this->reel->fresh()->caption);
        $this->getJson(route('planner.data.item', $this->reel->id))->assertOk()
            ->assertJsonPath('item.caption_handle', 'janethospitaltrichy')
            ->assertJsonPath('item.has_caption', true);
    }

    public function test_view_only_cannot_write_or_keep_captions(): void
    {
        UserPermission::query()->where('ability', 'edit')->delete();
        $this->fakeGemini();

        $this->actingAs($this->user->fresh())->postJson(route('planner.data.captions', $this->reel->id))->assertForbidden();
        $this->putJson(route('planner.data.caption', $this->reel->id), ['caption' => 'x'])->assertForbidden();
        Http::assertNothingSent();
    }
}
