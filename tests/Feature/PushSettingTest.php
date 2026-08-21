<?php

namespace Tests\Feature;

use App\Models\PushSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The studio's Firebase connection, admin-only under Setup.
 *
 * Mirrors NotionSettingTest's coverage, plus the two rules unique to this
 * screen: a blank service account leaves it unchanged (it's a secret), but
 * a blank web config genuinely clears it (it isn't one) -- and a
 * project-id mismatch between the two halves is caught at save time.
 */
class PushSettingTest extends TestCase
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

    private function validServiceAccountJson(string $projectId = 'chakra-test'): string
    {
        return json_encode([
            'type' => 'service_account',
            'project_id' => $projectId,
            'client_email' => 'firebase-adminsdk@'.$projectId.'.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
        ]);
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $this->get(route('push.edit'))->assertRedirect(route('login'));
        $this->put(route('push.update'), [])->assertRedirect(route('login'));
        $this->post(route('push.test'))->assertRedirect(route('login'));
    }

    public function test_an_employee_cannot_reach_the_settings_screen(): void
    {
        $this->actingAs($this->employee())
            ->get(route('push.edit'))
            ->assertForbidden();
    }

    public function test_the_screen_shows_not_set_when_empty(): void
    {
        $this->actingAs($this->admin())
            ->get(route('push.edit'))
            ->assertOk()
            ->assertSee('Not set');
    }

    public function test_saving_a_valid_service_account_persists_it_and_reads_back_as_plaintext(): void
    {
        $json = $this->validServiceAccountJson();

        $this->actingAs($this->admin())
            ->put(route('push.update'), ['service_account_json' => $json])
            ->assertRedirect(route('push.edit'));

        $this->assertSame($json, PushSetting::current()->service_account_json);
    }

    public function test_the_raw_database_column_is_not_plaintext(): void
    {
        $json = $this->validServiceAccountJson();

        $this->actingAs($this->admin())->put(route('push.update'), ['service_account_json' => $json]);

        $raw = DB::table('push_settings')->value('service_account_json');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($json, $raw);
    }

    public function test_the_rendered_screen_never_contains_the_plaintext_key(): void
    {
        PushSetting::current()->update(['service_account_json' => $this->validServiceAccountJson()]);

        $this->actingAs($this->admin())
            ->get(route('push.edit'))
            ->assertOk()
            ->assertDontSee('fake', escape: false); // the fake private key body
    }

    public function test_malformed_json_is_a_validation_error(): void
    {
        $this->actingAs($this->admin())
            ->put(route('push.update'), ['service_account_json' => 'not json at all'])
            ->assertSessionHasErrors('service_account_json');

        $this->assertNull(PushSetting::current()->service_account_json);
    }

    public function test_json_missing_a_required_key_is_a_validation_error(): void
    {
        $incomplete = json_encode(['type' => 'service_account', 'project_id' => 'x']);

        $this->actingAs($this->admin())
            ->put(route('push.update'), ['service_account_json' => $incomplete])
            ->assertSessionHasErrors('service_account_json');
    }

    public function test_saving_blank_keeps_the_existing_service_account(): void
    {
        $json = $this->validServiceAccountJson();
        PushSetting::current()->update(['service_account_json' => $json]);

        $this->actingAs($this->admin())
            ->put(route('push.update'), ['service_account_json' => ''])
            ->assertRedirect(route('push.edit'));

        $this->assertSame($json, PushSetting::current()->service_account_json);
    }

    /**
     * Unlike the secret, blank on this field means clear -- it is shown in
     * plain text on the form, so an empty submit unambiguously means the
     * admin removed it.
     */
    public function test_saving_blank_clears_the_web_config(): void
    {
        PushSetting::current()->update(['web_config' => '{"projectId":"x"}']);

        $this->actingAs($this->admin())
            ->put(route('push.update'), ['service_account_json' => '', 'web_config' => ''])
            ->assertRedirect(route('push.edit'));

        $this->assertNull(PushSetting::current()->web_config);
    }

    public function test_a_project_id_mismatch_between_the_two_halves_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put(route('push.update'), [
                'service_account_json' => $this->validServiceAccountJson('project-a'),
                'web_config' => json_encode(['projectId' => 'project-b']),
            ])
            ->assertSessionHasErrors('web_config');

        $this->assertNull(PushSetting::current()->service_account_json);
    }

    public function test_matching_project_ids_save_cleanly(): void
    {
        $this->actingAs($this->admin())
            ->put(route('push.update'), [
                'service_account_json' => $this->validServiceAccountJson('project-a'),
                'web_config' => json_encode(['projectId' => 'project-a']),
            ])
            ->assertRedirect(route('push.edit'))
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue(PushSetting::current()->projectsMatch());
    }

    public function test_saving_stamps_who_changed_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('push.update'), ['service_account_json' => $this->validServiceAccountJson()]);

        $this->assertSame($admin->id, PushSetting::current()->updated_by_id);
    }

    public function test_the_test_button_asks_the_admin_to_opt_in_first_when_they_have_no_device(): void
    {
        PushSetting::current()->update(['service_account_json' => $this->validServiceAccountJson()]);

        $this->actingAs($this->admin())
            ->post(route('push.test'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, "haven't turned notifications on"));
    }
}
