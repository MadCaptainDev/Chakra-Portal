<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The company-logo upload used to go through Storage::disk('public'), which
 * is only browser-reachable via the public/storage symlink -- and this
 * host's PHP has symlink() disabled, so uploads landed at an unreachable
 * "storage/..." path. See PublicUpload's own doc block and
 * [[public-uploads-convention]] in project memory. Fixed to write straight
 * under public/uploads like every other upload in the app.
 */
class SettingsLogoUploadTest extends TestCase
{
    use RefreshDatabase;

    private array $writtenFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_uploaded_logos_are_stored_under_public_uploads_not_the_storage_disk(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('settings.update'), [
            'company_name' => 'Chakra',
            'signature_name' => 'Someone',
            'signature_title' => 'CEO',
            'invoice_prefix' => 'CP-',
            'quotation_prefix' => 'QT-',
            'footer_text' => 'Thanks',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'app_studio_logo' => UploadedFile::fake()->image('studio-logo.png'),
        ]);

        $response->assertRedirect(route('settings.edit'));

        $settings = CompanySetting::current();

        $this->assertStringStartsWith('uploads/logos/', $settings->logo_path);
        $this->assertStringStartsWith('uploads/logos/', $settings->app_studio_logo_path);

        $logoAbsolute = public_path($settings->logo_path);
        $studioLogoAbsolute = public_path($settings->app_studio_logo_path);
        $this->writtenFiles = [$logoAbsolute, $studioLogoAbsolute];

        $this->assertFileExists($logoAbsolute);
        $this->assertFileExists($studioLogoAbsolute);
    }
}
