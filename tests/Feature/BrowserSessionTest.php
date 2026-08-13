<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BrowserSessions;
use App\Support\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrowserSessionTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        // The array driver keeps no rows, and rows are the whole subject here.
        config(['session.driver' => 'database', 'session.lifetime' => 120]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sessionRow(User $user, string $id, array $overrides = []): string
    {
        DB::table('sessions')->insert(array_merge([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => self::CHROME_WINDOWS,
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ], $overrides));

        return hash('sha256', $id);
    }

    // ——— What the page shows ———

    public function test_the_profile_lists_the_devices_this_account_is_signed_in_on(): void
    {
        $user = User::factory()->create();
        $this->sessionRow($user, 'other-device', ['user_agent' => self::SAFARI_IPHONE]);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee("Where you're signed in", false)
            ->assertSee('Safari on iPhone');
    }

    public function test_another_persons_devices_are_never_listed(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $this->sessionRow($stranger, 'strangers-device', ['user_agent' => self::SAFARI_IPHONE]);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Safari on iPhone');
    }

    public function test_a_session_id_is_never_written_into_the_page(): void
    {
        $user = User::factory()->create();
        $this->sessionRow($user, 'a-very-secret-session-id');

        // The id is the cookie value. A page that prints it hands out a
        // working login to anyone who can read the screen.
        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('a-very-secret-session-id');
    }

    public function test_a_session_older_than_the_lifetime_is_not_listed(): void
    {
        $user = User::factory()->create();
        $this->sessionRow($user, 'long-gone', [
            'user_agent' => self::SAFARI_IPHONE,
            'last_activity' => now()->subDay()->getTimestamp(),
        ]);

        // Laravel prunes expired rows on a lottery, so they linger. Listing one
        // would tell somebody they are signed in somewhere they are not.
        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Safari on iPhone');
    }

    // ——— Signing a device out ———

    public function test_a_device_can_be_signed_out(): void
    {
        $user = User::factory()->create();
        $handle = $this->sessionRow($user, 'the-old-laptop');

        $this->actingAs($user)
            ->delete(route('devices.destroy'), ['handle' => $handle])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sessions', ['id' => 'the-old-laptop']);
    }

    public function test_a_handle_from_somebody_elses_device_matches_nothing(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $handle = $this->sessionRow($stranger, 'strangers-device');

        $this->actingAs($user)
            ->delete(route('devices.destroy'), ['handle' => $handle])
            ->assertRedirect();

        // Their session is theirs. A handle read off their screen buys nothing.
        $this->assertDatabaseHas('sessions', ['id' => 'strangers-device']);
    }

    public function test_signing_out_everything_else_clears_the_rest(): void
    {
        $user = User::factory()->create();
        $this->sessionRow($user, 'laptop');
        $this->sessionRow($user, 'phone');

        $this->actingAs($user)
            ->delete(route('devices.destroy-others'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sessions', ['id' => 'laptop']);
        $this->assertDatabaseMissing('sessions', ['id' => 'phone']);
    }

    /*
     * The next two go through App\Support\BrowserSessions rather than the
     * route. The test client does not carry a session cookie between requests,
     * so the id a request runs under cannot be known before it is made -- which
     * makes "everything except the device asking" untestable over HTTP even
     * though it is exactly right in a browser. The logic is tested where it
     * actually lives instead of being tested to a weaker standard.
     */

    public function test_the_device_asking_is_the_one_left_behind(): void
    {
        $user = User::factory()->create();
        $this->sessionRow($user, 'laptop');
        $this->sessionRow($user, 'phone');
        $this->sessionRow($user, 'the-one-asking');

        $this->assertSame(2, BrowserSessions::forgetOthers($user, 'the-one-asking'));

        $this->assertDatabaseHas('sessions', ['id' => 'the-one-asking']);
        $this->assertDatabaseMissing('sessions', ['id' => 'laptop']);
    }

    public function test_signing_out_everything_else_leaves_other_people_alone(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $this->sessionRow($user, 'my-laptop');
        $this->sessionRow($stranger, 'their-laptop');

        BrowserSessions::forgetOthers($user, 'the-one-asking');

        $this->assertDatabaseHas('sessions', ['id' => 'their-laptop']);
        $this->assertDatabaseMissing('sessions', ['id' => 'my-laptop']);
    }

    public function test_a_handle_names_one_session_and_no_other(): void
    {
        // The guard that refuses to sign out the device you are holding rests
        // on this: the handle on the page for "this device" matches the
        // request's own session id and nothing else.
        $this->assertTrue(BrowserSessions::matches(hash('sha256', 'abc'), 'abc'));
        $this->assertFalse(BrowserSessions::matches(hash('sha256', 'abc'), 'abd'));
        $this->assertFalse(BrowserSessions::matches(str_repeat('0', 64), 'abc'));
    }

    public function test_a_handle_that_matches_nothing_is_not_an_error(): void
    {
        $user = User::factory()->create();

        // What a second click on the same button looks like.
        $this->actingAs($user)
            ->delete(route('devices.destroy'), ['handle' => str_repeat('a', 64)])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');
    }

    public function test_a_malformed_handle_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('devices.destroy'), ['handle' => 'nope'])
            ->assertSessionHasErrors('handle');
    }

    public function test_a_guest_cannot_reach_either_route(): void
    {
        $this->delete(route('devices.destroy'), ['handle' => str_repeat('a', 64)])
            ->assertRedirect(route('login'));

        $this->delete(route('devices.destroy-others'))->assertRedirect(route('login'));
    }

    // ——— Reading a user agent ———

    public function test_user_agents_are_read_into_something_a_person_recognises(): void
    {
        $cases = [
            self::CHROME_WINDOWS => ['Chrome on Windows', Device::DESKTOP],
            self::SAFARI_IPHONE => ['Safari on iPhone', Device::PHONE],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'
                => ['Safari on Mac', Device::DESKTOP],
            'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Safari/604.1'
                => ['Safari on iPad', Device::TABLET],
            'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36'
                => ['Chrome on Android', Device::PHONE],
        ];

        foreach ($cases as $agent => [$label, $kind]) {
            $read = Device::describe($agent);

            $this->assertSame($label, $read['label'], $agent);
            $this->assertSame($kind, $read['kind'], $agent);
        }
    }

    public function test_the_impostors_are_read_before_the_browser_they_impersonate(): void
    {
        // Edge and Opera both claim to be Chrome, and Chrome claims to be
        // Safari. Test the order, because it is the whole trick.
        $this->assertSame('Edge on Windows', Device::describe(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36 Edg/126.0'
        )['label']);

        $this->assertSame('Opera on Windows', Device::describe(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36 OPR/110.0'
        )['label']);
    }

    public function test_an_agent_nobody_recognises_says_so(): void
    {
        // Usually a script or a very old phone. Either is worth a second look
        // before it is dismissed, so it must not be quietly labelled Chrome.
        $this->assertSame('Unknown device', Device::describe('curl/8.4.0')['label']);
        $this->assertSame('Unknown device', Device::describe(null)['label']);
    }
}
