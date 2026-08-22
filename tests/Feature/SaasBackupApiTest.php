<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SaasBackup;
use App\Models\SaasProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The backup + license API a client-built app (starting with DJ
 * Thangamaaligai's ERP) calls from a different server entirely -- no
 * session, a bearer token is the only way in. Mirrors McpServerTest's shape
 * for the same kind of route.
 */
class SaasBackupApiTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): SaasProduct
    {
        $client = Client::create(['name' => 'DJ Thangamaaligai']);

        return SaasProduct::create($overrides + [
            'client_id' => $client->id,
            'name' => 'DJ Thangamaaligai ERP',
        ]);
    }

    private function tokenFor(SaasProduct $product): string
    {
        return $product->issueToken();
    }

    // -- Auth -----------------------------------------------------------

    public function test_a_request_with_no_token_is_refused(): void
    {
        $this->getJson(route('api.saas.license'))
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate');
    }

    public function test_a_request_with_a_bad_token_is_refused(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer saas_not-a-real-token'])
            ->getJson(route('api.saas.license'))
            ->assertStatus(401);
    }

    public function test_a_request_from_a_foreign_origin_is_refused(): void
    {
        $token = $this->tokenFor($this->product());

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Origin' => 'https://evil.example.com'])
            ->getJson(route('api.saas.license'))
            ->assertStatus(403);
    }

    // -- Upload -----------------------------------------------------------

    public function test_uploading_a_backup_stores_it_on_the_private_disk(): void
    {
        Storage::fake('local');
        $product = $this->product();
        $token = $this->tokenFor($product);

        $file = UploadedFile::fake()->createWithContent('dump.sql.gz', 'fake-database-dump-bytes');

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post(route('api.saas.backups.store'), ['file' => $file]);

        $response->assertStatus(201)->assertJsonStructure(['id', 'taken_at', 'size_bytes', 'checksum']);

        $backup = SaasBackup::firstOrFail();
        $this->assertSame($product->id, $backup->saas_product_id);
        Storage::disk('local')->assertExists($backup->disk_path);
        $this->assertSame(hash('sha256', 'fake-database-dump-bytes'), $backup->checksum);
    }

    public function test_a_backup_uploaded_by_one_product_is_invisible_to_another(): void
    {
        Storage::fake('local');
        $productA = $this->product();
        $productB = $this->product(['name' => 'Someone Else\'s App']);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor($productA)])
            ->post(route('api.saas.backups.store'), ['file' => UploadedFile::fake()->create('a.sql', 10)]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor($productB)])
            ->getJson(route('api.saas.backups.index'))
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_retention_prunes_the_oldest_backup_once_the_count_is_exceeded(): void
    {
        Storage::fake('local');
        $product = $this->product(['backup_retention_count' => 2]);
        $token = $this->tokenFor($product);

        foreach (range(1, 3) as $i) {
            $this->withHeaders(['Authorization' => 'Bearer '.$token])->post(route('api.saas.backups.store'), [
                'file' => UploadedFile::fake()->createWithContent("dump{$i}.sql", "content-{$i}"),
                'taken_at' => now()->subDays(3 - $i)->toIso8601String(),
            ]);
        }

        $this->assertSame(2, $product->backups()->count());
        // The oldest (day -2, i.e. the first uploaded) is the one pruned.
        $this->assertSame(
            ['content-2', 'content-3'],
            $product->backups()->newestFirst()->get()->reverse()->values()
                ->map(fn (SaasBackup $b) => Storage::disk('local')->get($b->disk_path))->all()
        );
    }

    // -- Download ---------------------------------------------------------

    public function test_downloading_someone_elses_backup_is_a_404_not_a_403(): void
    {
        Storage::fake('local');
        $owner = $this->product();
        $intruder = $this->product(['name' => 'Someone Else']);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor($owner)])
            ->post(route('api.saas.backups.store'), ['file' => UploadedFile::fake()->create('a.sql', 10)]);
        $backup = SaasBackup::firstOrFail();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor($intruder)])
            ->get(route('api.saas.backups.download', $backup))
            ->assertNotFound();
    }

    public function test_downloading_your_own_backup_streams_the_file(): void
    {
        Storage::fake('local');
        $product = $this->product();
        $token = $this->tokenFor($product);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])->post(route('api.saas.backups.store'), [
            'file' => UploadedFile::fake()->createWithContent('dump.sql', 'the actual backup bytes'),
        ]);
        $backup = SaasBackup::firstOrFail();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get(route('api.saas.backups.download', $backup))
            ->assertOk();
    }

    // -- License status ------------------------------------------------

    public function test_a_never_billed_product_is_active(): void
    {
        $token = $this->tokenFor($this->product());

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(route('api.saas.license'))
            ->assertOk()
            ->assertJson(['status' => 'active']);
    }

    public function test_a_product_paid_up_is_active(): void
    {
        $token = $this->tokenFor($this->product(['amc_paid_until' => now()->addMonth()->toDateString()]));

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(route('api.saas.license'))
            ->assertJson(['status' => 'active']);
    }

    public function test_a_product_past_its_paid_until_date_is_overdue(): void
    {
        $token = $this->tokenFor($this->product(['amc_paid_until' => now()->subDay()->toDateString()]));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(route('api.saas.license'));

        $response->assertJson(['status' => 'overdue']);
        $this->assertStringContainsString('Please arrange payment', $response->json('message'));
    }

    public function test_a_suspended_product_is_suspended_regardless_of_payment(): void
    {
        $product = $this->product(['amc_paid_until' => now()->addYear()->toDateString()]);
        $product->suspend(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $token = $this->tokenFor($product);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(route('api.saas.license'))
            ->assertJson(['status' => 'suspended']);
    }

    public function test_reinstating_a_suspended_product_returns_it_to_active(): void
    {
        $product = $this->product();
        $product->suspend(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $product->reinstate();
        $token = $this->tokenFor($product);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(route('api.saas.license'))
            ->assertJson(['status' => 'active']);
    }

    // -- Config -----------------------------------------------------------

    public function test_a_product_can_fetch_its_own_configuration(): void
    {
        $product = $this->product(['backup_retention_count' => 5]);
        $token = $this->tokenFor($product);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(route('api.saas.config'))
            ->assertOk()
            ->assertJson([
                'name' => $product->name,
                'backup_retention_count' => 5,
                'amc_frequency' => null,
            ]);
    }

    public function test_config_reports_the_amc_frequency_once_billing_is_set_up(): void
    {
        $product = $this->product();
        $schedule = \App\Models\RecurringInvoice::create([
            'client_id' => $product->client_id,
            'saas_product_id' => $product->id,
            'frequency' => 'monthly',
            'next_run_on' => now()->addMonth(),
            'is_active' => true,
            'created_by' => \App\Models\User::factory()->create()->id,
        ]);
        $product->update(['recurring_invoice_id' => $schedule->id]);
        $token = $this->tokenFor($product->refresh());

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(route('api.saas.config'))
            ->assertJson(['amc_frequency' => 'monthly']);
    }

    public function test_config_requires_a_valid_token_like_every_other_saas_endpoint(): void
    {
        $this->getJson(route('api.saas.config'))->assertStatus(401);
    }
}
