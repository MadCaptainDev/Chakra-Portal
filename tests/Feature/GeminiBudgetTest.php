<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Notifications\AiBudgetAlert;
use App\Services\Ai\GeminiBudget;
use App\Services\Ai\GeminiBudgetExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The monthly Gemini spend limit: every call metered, none sent past the limit. */
class GeminiBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent';

    protected function setUp(): void
    {
        parent::setUp();
        GeminiBudget::reset();
        AiSetting::current()->forceFill(['gemini_monthly_cap_usd' => 5])->save();
    }

    private function fakeGemini(int $prompt = 10_000, int $audio = 4_000, int $out = 1_000, int $thoughts = 500): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/upload/*' => Http::response(['file' => ['name' => 'files/x', 'uri' => 'u', 'state' => 'ACTIVE']]),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{}']]]]],
                'usageMetadata' => [
                    'promptTokenCount' => $prompt,
                    'candidatesTokenCount' => $out,
                    'thoughtsTokenCount' => $thoughts,
                    'promptTokensDetails' => [['modality' => 'VIDEO', 'tokenCount' => $prompt - $audio], ['modality' => 'AUDIO', 'tokenCount' => $audio]],
                ],
            ]),
        ]);
    }

    private function spend(float $usd): void
    {
        DB::table('ai_usage')->insert(['provider' => 'gemini', 'model' => 'gemini-3.5-flash', 'feature' => 'transcribe', 'cost_usd' => $usd, 'created_at' => now()]);
    }

    public function test_every_gemini_call_is_priced_from_the_tokens_google_reports(): void
    {
        $this->fakeGemini();

        GeminiBudget::during('captions', fn () => Http::post(self::URL, ['contents' => []]));

        $row = DB::table('ai_usage')->first();
        $this->assertSame(['gemini', 'gemini-3.5-flash', 'captions', 6000, 4000, 1500], [$row->provider, $row->model, $row->feature, $row->input_tokens, $row->audio_tokens, $row->output_tokens]);
        // flash: 6000 × $0.50 + 4000 × $1.00 + 1500 × $3.00, per million
        $this->assertEqualsWithDelta(0.0115, (float) $row->cost_usd, 0.000001);
        $this->assertEqualsWithDelta(0.0115, GeminiBudget::spentThisMonth(), 0.000001);
    }

    public function test_uploads_and_other_hosts_are_not_counted(): void
    {
        $this->fakeGemini();
        Http::fake(['example.test/*' => Http::response(['usageMetadata' => ['promptTokenCount' => 99]])]);

        Http::post('https://generativelanguage.googleapis.com/upload/v1beta/files', []);
        Http::get('https://example.test/v1beta/models/x:generateContent');

        $this->assertSame(0, DB::table('ai_usage')->count());
    }

    public function test_an_unknown_model_is_charged_at_the_dearest_price(): void
    {
        $this->assertSame(config('ai.gemini_prices.*'), GeminiBudget::price('gemini-9-ultra'));
        $this->assertSame(config('ai.gemini_prices.flash-lite'), GeminiBudget::price('gemini-3.5-flash-lite'));
        $this->assertSame(config('ai.gemini_prices.flash'), GeminiBudget::price('gemini-flash-latest'));
    }

    public function test_past_the_limit_nothing_is_sent_and_admins_are_told_once(): void
    {
        Notification::fake();
        $this->fakeGemini();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->spend(4.97);

        foreach ([1, 2] as $try) {
            try {
                Http::post(self::URL, ['contents' => []]);
                $this->fail('The call should have been refused.');
            } catch (GeminiBudgetExceeded $e) {
                $this->assertStringContainsString('limit of $5.00 is used up', $e->getMessage());
            }
        }

        Http::assertNothingSent();
        Notification::assertSentToTimes($admin, AiBudgetAlert::class, 1);
        Notification::assertSentTo($admin, fn (AiBudgetAlert $n) => $n->level === 100);
    }

    public function test_crossing_80_percent_warns_once(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->spend(3.99);
        $this->fakeGemini(prompt: 1_000_000, audio: 0, out: 0, thoughts: 0); // $0.50

        Http::post(self::URL, []);
        Http::post(self::URL, []);

        Notification::assertSentToTimes($admin, AiBudgetAlert::class, 1);
        Notification::assertSentTo($admin, fn (AiBudgetAlert $n) => $n->level === 80);
    }

    public function test_last_months_spend_does_not_count(): void
    {
        $this->fakeGemini();
        DB::table('ai_usage')->insert(['provider' => 'gemini', 'model' => 'm', 'cost_usd' => 9, 'created_at' => now()->subMonth()]);

        Http::post(self::URL, []);

        $this->assertSame(2, DB::table('ai_usage')->count());
    }

    public function test_an_admin_sees_the_spend_and_changes_the_limit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->spend(1.25);

        $this->actingAs($admin)->get(route('ai.edit'))->assertOk()
            ->assertSee('Gemini spend limit')->assertSee('$1.25')->assertSee('of $5.00 this month', false);

        $this->put(route('ai.gemini-cap'), ['gemini_monthly_cap_usd' => 10])->assertRedirect();
        $this->assertSame(10.0, GeminiBudget::cap());
        $this->put(route('ai.gemini-cap'), ['gemini_monthly_cap_usd' => -1])->assertSessionHasErrors('gemini_monthly_cap_usd');
    }
}
