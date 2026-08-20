<?php

namespace Tests\Feature;

use App\Models\NotionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The studio's Notion integration token, admin-only under Setup.
 *
 * Mirrors InstagramConnectionTest's settings-screen coverage: what a guest
 * and a non-admin cannot reach, the secret never rendering back, and a
 * blank submit meaning "leave it alone" rather than "clear it".
 */
class NotionSettingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $this->get(route('notion.edit'))->assertRedirect(route('login'));
        $this->put(route('notion.update'), ['api_key' => 'x'])->assertRedirect(route('login'));
        $this->post(route('notion.recheck'))->assertRedirect(route('login'));
    }

    public function test_an_employee_cannot_reach_the_settings_screen(): void
    {
        $this->actingAs($this->employee())
            ->get(route('notion.edit'))
            ->assertForbidden();
    }

    public function test_the_screen_shows_not_set_when_empty(): void
    {
        $this->actingAs($this->admin())
            ->get(route('notion.edit'))
            ->assertOk()
            ->assertSee('Not set');
    }

    public function test_saving_a_key_persists_it_and_it_reads_back_as_plaintext(): void
    {
        $this->actingAs($this->admin())
            ->put(route('notion.update'), ['api_key' => 'ntn_real_secret_value'])
            ->assertRedirect(route('notion.edit'));

        $this->assertSame('ntn_real_secret_value', NotionSetting::current()->api_key);
    }

    public function test_the_raw_database_column_is_not_plaintext(): void
    {
        $this->actingAs($this->admin())
            ->put(route('notion.update'), ['api_key' => 'ntn_real_secret_value']);

        $raw = DB::table('notion_settings')->value('api_key');

        $this->assertNotNull($raw);
        $this->assertNotSame('ntn_real_secret_value', $raw);
        $this->assertStringNotContainsString('ntn_real_secret_value', $raw);
    }

    public function test_the_rendered_screen_never_contains_the_plaintext_key(): void
    {
        NotionSetting::current()->update(['api_key' => 'ntn_real_secret_value']);

        // A configured key makes edit() call sourceAvailability(), which is
        // a real HTTP round trip -- fake it rather than hitting Notion.
        Http::fake(['api.notion.com/*' => Http::response(['results' => []], 200)]);

        $this->actingAs($this->admin())
            ->get(route('notion.edit'))
            ->assertOk()
            ->assertDontSee('ntn_real_secret_value');
    }

    public function test_saving_blank_keeps_the_existing_key(): void
    {
        NotionSetting::current()->update(['api_key' => 'ntn_original_key']);

        $this->actingAs($this->admin())
            ->put(route('notion.update'), ['api_key' => ''])
            ->assertRedirect(route('notion.edit'));

        $this->assertSame('ntn_original_key', NotionSetting::current()->api_key);
    }

    public function test_saving_stamps_who_changed_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('notion.update'), ['api_key' => 'ntn_x']);

        $this->assertSame($admin->id, NotionSetting::current()->updated_by_id);
    }

    public function test_recheck_clears_the_discovery_cache(): void
    {
        Cache::put('notion.discovered_databases', ['stale' => true], 3600);

        $this->actingAs($this->admin())
            ->post(route('notion.recheck'))
            ->assertRedirect(route('notion.edit'));

        $this->assertFalse(Cache::has('notion.discovered_databases'));
    }
}
