<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\ClientCredentialView;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientCredentialTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'sva-insta-2026!';

    private function client(): Client
    {
        return Client::create(['name' => 'SVA Silks', 'notion_venture' => 'SVA Silks']);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function staffWith(array $abilities): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($abilities as $ability) {
            UserPermission::create([
                'user_id' => $user->id,
                'module' => 'clients',
                'ability' => $ability,
            ]);
        }

        return $user;
    }

    private function credential(Client $client, array $overrides = []): ClientCredential
    {
        $credential = new ClientCredential(array_merge([
            'client_id' => $client->id,
            'kind' => ClientCredential::KIND_INSTAGRAM,
            'label' => 'Main account',
            'username' => '@svasilks',
            'secret' => self::PASSWORD,
            'notes' => 'Recovery code 12345',
        ], $overrides));

        $credential->save();

        return $credential;
    }

    // ——— How it is stored ———

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $credential = $this->credential($this->client());

        $raw = DB::table('client_credentials')->where('id', $credential->id)->first();

        // The column must not contain the password in any readable form.
        $this->assertNotSame(self::PASSWORD, $raw->secret);
        $this->assertStringNotContainsString(self::PASSWORD, $raw->secret);
        // And it must come back out intact -- encrypted, not hashed, because
        // it has to be typed into Instagram.
        $this->assertSame(self::PASSWORD, $credential->fresh()->secret);
    }

    public function test_the_notes_are_encrypted_too(): void
    {
        $credential = $this->credential($this->client());

        $raw = DB::table('client_credentials')->where('id', $credential->id)->first();

        // Recovery codes end up here whether or not anyone intended them to.
        $this->assertStringNotContainsString('12345', $raw->notes);
    }

    public function test_the_username_stays_readable(): void
    {
        $credential = $this->credential($this->client());

        $raw = DB::table('client_credentials')->where('id', $credential->id)->first();

        // A handle is public, and leaving it clear means the list renders
        // without decrypting anything.
        $this->assertSame('@svasilks', $raw->username);
    }

    public function test_serialising_the_model_never_carries_the_secret(): void
    {
        $credential = $this->credential($this->client());

        // A stray toJson() in a log line or a debug response would otherwise
        // write every client's password into a file.
        $this->assertStringNotContainsString(self::PASSWORD, $credential->toJson());
        $this->assertArrayNotHasKey('secret', $credential->toArray());
    }

    // ——— Who may see them ———

    public function test_the_panel_is_hidden_from_someone_without_the_credentials_ability(): void
    {
        $client = $this->client();
        $this->credential($client);

        // They can manage the client record, but not read its passwords.
        $this->actingAs($this->staffWith(['view', 'edit']))
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee('Account logins')
            ->assertDontSee('@svasilks');
    }

    public function test_someone_with_the_ability_sees_the_panel_but_not_the_password(): void
    {
        $client = $this->client();
        $this->credential($client);

        // The handle is listed; the password is not in the page at all.
        $this->actingAs($this->staffWith(['view', 'credentials']))
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Account logins')
            ->assertSee('@svasilks')
            ->assertDontSee(self::PASSWORD);
    }

    public function test_revealing_returns_the_password(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);

        $this->actingAs($this->staffWith(['view', 'credentials']))
            ->post(route('clients.credentials.reveal', [$client, $credential]))
            ->assertOk()
            ->assertJsonPath('secret', self::PASSWORD);
    }

    public function test_revealing_is_refused_without_the_ability(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);

        $this->actingAs($this->staffWith(['view', 'edit', 'delete']))
            ->post(route('clients.credentials.reveal', [$client, $credential]))
            ->assertForbidden();

        $this->assertSame(0, ClientCredentialView::count());
    }

    public function test_an_employee_with_no_client_permission_cannot_reach_the_screen(): void
    {
        $client = $this->client();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('clients.show', $client))
            ->assertForbidden();
    }

    public function test_a_client_login_cannot_reach_any_of_this(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);
        $login = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);

        // Not even their own credentials -- this is the studio's record of
        // holding them, not a password manager for the client.
        $this->actingAs($login)->get(route('clients.show', $client))->assertForbidden();
        $this->actingAs($login)
            ->post(route('clients.credentials.reveal', [$client, $credential]))
            ->assertForbidden();
    }

    // ——— The audit trail ———

    public function test_every_reveal_is_written_down(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);
        $viewer = $this->staffWith(['view', 'credentials']);

        $this->actingAs($viewer)->post(route('clients.credentials.reveal', [$client, $credential]));

        $view = ClientCredentialView::firstOrFail();
        $this->assertSame($viewer->id, $view->user_id);
        $this->assertSame($credential->id, $view->client_credential_id);
        $this->assertNotNull($view->viewed_at);
    }

    public function test_the_audit_survives_the_viewer_being_deleted(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);
        $viewer = $this->staffWith(['view', 'credentials']);

        $this->actingAs($viewer)->post(route('clients.credentials.reveal', [$client, $credential]));

        // Deleting an account must not quietly erase what it looked at -- that
        // is exactly the row somebody would want gone.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $viewer->delete();
    }

    // ——— Writing them ———

    public function test_a_credential_can_be_added(): void
    {
        $client = $this->client();

        $this->actingAs($this->staffWith(['view', 'credentials']))
            ->post(route('clients.credentials.store', $client), [
                'kind' => ClientCredential::KIND_YOUTUBE,
                'label' => 'Brand channel',
                'username' => 'sva@gmail.com',
                'secret' => 'a-new-password',
            ])->assertRedirect();

        $credential = ClientCredential::firstOrFail();
        $this->assertSame('a-new-password', $credential->secret);
        $this->assertSame($client->id, $credential->client_id);
    }

    public function test_editing_without_a_new_password_keeps_the_old_one(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);

        // The form cannot show the current value, so it always arrives blank.
        // Treating blank as a deletion would wipe the password every time
        // somebody fixed a typo in the label.
        $this->actingAs($this->staffWith(['view', 'credentials']))
            ->put(route('clients.credentials.update', [$client, $credential]), [
                'kind' => ClientCredential::KIND_INSTAGRAM,
                'label' => 'Main account (renamed)',
                'username' => '@svasilks',
                'secret' => '',
            ])->assertRedirect();

        $credential->refresh();
        $this->assertSame('Main account (renamed)', $credential->label);
        $this->assertSame(self::PASSWORD, $credential->secret);
    }

    public function test_a_new_password_replaces_the_old_one(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);

        $this->actingAs($this->staffWith(['view', 'credentials']))
            ->put(route('clients.credentials.update', [$client, $credential]), [
                'kind' => ClientCredential::KIND_INSTAGRAM,
                'username' => '@svasilks',
                'secret' => 'rotated-password',
            ])->assertRedirect();

        $this->assertSame('rotated-password', $credential->refresh()->secret);
    }

    public function test_a_credential_belonging_to_another_client_is_a_404(): void
    {
        $mine = $this->client();
        $theirs = Client::create(['name' => 'Other Brand']);
        $notMine = $this->credential($theirs);

        $this->actingAs($this->staffWith(['view', 'credentials']))
            ->post(route('clients.credentials.reveal', [$mine, $notMine]))
            ->assertNotFound();
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $client = $this->client();

        $this->actingAs($this->staffWith(['view', 'credentials']))
            ->post(route('clients.credentials.store', $client), [
                'kind' => 'bank-account',
                'secret' => 'nope',
            ])->assertSessionHasErrors('kind');

        $this->assertSame(0, ClientCredential::count());
    }

    public function test_deleting_a_client_takes_its_credentials_with_it(): void
    {
        $client = $this->client();
        $this->credential($client);

        $client->delete();

        $this->assertSame(0, ClientCredential::count());
    }

    // ——— The module itself ———

    public function test_the_clients_module_gates_the_whole_screen(): void
    {
        $client = $this->client();

        $this->actingAs($this->staffWith(['view']))
            ->get(route('clients.index'))->assertOk();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('clients.index'))->assertForbidden();
    }

    public function test_manage_covers_every_other_ability_in_the_module(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);
        $manager = $this->staffWith(['manage']);

        $this->actingAs($manager)->get(route('clients.show', $client))->assertOk();
        $this->actingAs($manager)
            ->post(route('clients.credentials.reveal', [$client, $credential]))
            ->assertOk();
        $this->actingAs($manager)->get(route('clients.edit', $client))->assertOk();
    }

    public function test_the_money_block_stays_admin_only(): void
    {
        $client = $this->client();

        // Being given the Clients module to keep records and logins tidy is
        // not being handed what every client has paid and owes.
        $this->actingAs($this->staffWith(['manage']))
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertViewHas('invoices', fn ($invoices) => $invoices->isEmpty());
    }

    public function test_an_admin_still_reaches_everything(): void
    {
        $client = $this->client();
        $credential = $this->credential($client);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('clients.show', $client))->assertOk()->assertSee('Account logins');
        $this->actingAs($admin)
            ->post(route('clients.credentials.reveal', [$client, $credential]))
            ->assertOk()
            ->assertJsonPath('secret', self::PASSWORD);
    }
}
