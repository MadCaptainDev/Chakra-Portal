<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClientLogoTest extends TestCase
{
    use RefreshDatabase;

    /** Logos land in the real public/uploads, so each test clears up after itself. */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file(public_path($path))) {
                unlink(public_path($path));
            }
        }

        parent::tearDown();
    }

    public function test_the_logo_field_renders_on_both_screens(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user)->get(route('clients.create'))
            ->assertOk()
            ->assertSee('name="logo"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $this->actingAs($user)->get(route('clients.edit', $client))
            ->assertOk()
            ->assertSee('name="logo"', false)
            ->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_a_logo_can_be_uploaded_with_a_new_client(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('clients.store'), [
            'name' => 'Inside Manapparai',
            'logo' => UploadedFile::fake()->image('brand.png', 400, 400),
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('clients.index'));

        $client = Client::where('name', 'Inside Manapparai')->firstOrFail();
        $this->written[] = $client->logo_path;

        // public/uploads, never the storage disk -- Apache will not follow the
        // public/storage symlink, so anything served from there returns 403.
        $this->assertStringStartsWith('uploads/clients/', $client->logo_path);
        $this->assertFileExists(public_path($client->logo_path));
    }

    public function test_replacing_a_logo_removes_the_previous_file(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user)->put(route('clients.update', $client), [
            'name' => $client->name,
            'logo' => UploadedFile::fake()->image('first.png', 200, 200),
        ])->assertSessionHasNoErrors();

        $first = $client->refresh()->logo_path;
        $this->written[] = $first;

        $this->actingAs($user)->put(route('clients.update', $client), [
            'name' => $client->name,
            'logo' => UploadedFile::fake()->image('second.png', 200, 200),
        ])->assertSessionHasNoErrors();

        $second = $client->refresh()->logo_path;
        $this->written[] = $second;

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist(public_path($first));
        $this->assertFileExists(public_path($second));
    }

    public function test_a_logo_can_be_removed(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user)->put(route('clients.update', $client), [
            'name' => $client->name,
            'logo' => UploadedFile::fake()->image('brand.png', 200, 200),
        ])->assertSessionHasNoErrors();

        $path = $client->refresh()->logo_path;
        $this->written[] = $path;

        $this->actingAs($user)->put(route('clients.update', $client), [
            'name' => $client->name,
            'remove_logo' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertNull($client->refresh()->logo_path);
        $this->assertFileDoesNotExist(public_path($path));
    }

    public function test_an_svg_is_rejected(): void
    {
        $user = User::factory()->create();

        // SVG is a scriptable document; served from our own origin it would be
        // stored XSS, so the upload must not accept one.
        $response = $this->actingAs($user)->post(route('clients.store'), [
            'name' => 'Scriptable',
            'logo' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml'),
        ]);

        $response->assertSessionHasErrors('logo');
        $this->assertDatabaseMissing('clients', ['name' => 'Scriptable']);
    }

    public function test_updating_a_client_without_a_logo_leaves_the_existing_one_alone(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user)->put(route('clients.update', $client), [
            'name' => $client->name,
            'logo' => UploadedFile::fake()->image('brand.png', 200, 200),
        ])->assertSessionHasNoErrors();

        $path = $client->refresh()->logo_path;
        $this->written[] = $path;

        $this->actingAs($user)->put(route('clients.update', $client), [
            'name' => 'Renamed',
        ])->assertSessionHasNoErrors();

        $client->refresh();

        $this->assertSame('Renamed', $client->name);
        $this->assertSame($path, $client->logo_path);
        $this->assertFileExists(public_path($path));
    }
}
