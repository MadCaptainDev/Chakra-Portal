<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappCampaignMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Tests\TestCase;

/**
 * Exercises real Laravel worker attempt-counting against the `database`
 * queue driver -- not Bus::fake(), which bypasses attempts entirely -- to
 * prove that a job's own $tries actually protects it against this app's
 * `queue:listen --tries=1` convention (composer.json's "dev" script) when
 * something releases it back onto the queue repeatedly, the way RateLimited
 * middleware does every time a campaign send hits whatsapp-campaign's
 * 40/minute cap.
 *
 * Rather than fight the real rate limiter's own cache-backed timing (which
 * would make this test slow and non-deterministic), the job under test
 * releases itself unconditionally -- a direct stand-in for "this job just
 * got rate-limited" -- so what is actually being proven is Laravel's own
 * attempts-increment-on-every-pop mechanics against a job's $tries value,
 * which is exactly the mechanism finding 1 was about. ReleasingJob is a
 * standalone double rather than a SendWhatsappCampaignMessage subclass:
 * subclassing it tries to reinitialize the parent's readonly
 * $campaignLogId through Laravel's SerializesModels::__unserialize()
 * reflection restoration, which PHP refuses (a readonly property can only
 * be initialized once, from its declaring class's scope) -- an artifact of
 * subclassing a readonly-constructor job, unrelated to what this test
 * exists to prove. test_send_whatsapp_campaign_message_declares_the_apps_
 * tries_override below is what actually binds this test file to the real
 * class's $tries value.
 */
class QueueWorkerReleaseSurvivalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The finding is specific to the `database` driver -- attempts only
        // increment on every pop there. phpunit.xml defaults QUEUE_CONNECTION
        // to `sync` for every other test in the suite; this file needs the
        // real thing.
        config(['queue.default' => 'database']);

        // Illuminate\Queue\Worker itself never touches the failed_jobs
        // table -- that persistence is wired by queue:work/queue:listen's
        // own JobFailed listener (WorkCommand::logFailedJob()). Driving the
        // worker directly, the way this test does to get fine-grained
        // control over each pop, bypasses the Artisan command entirely, so
        // this registers the exact same listener by hand.
        $this->app['events']->listen(JobFailed::class, function (JobFailed $event) {
            $this->app['queue.failer']->log(
                $event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception
            );
        });
    }

    private function worker(): Worker
    {
        return app('queue.worker');
    }

    public function test_send_whatsapp_campaign_message_declares_the_apps_tries_override(): void
    {
        // Binds this file's simulated pop counts to the real class's actual
        // value -- if this fix's $tries ever regresses, this assertion (not
        // just the simulation below) is what catches it.
        $this->assertSame(25, (new SendWhatsappCampaignMessage(campaignLogId: 1))->tries);
    }

    public function test_a_job_with_tries_25_survives_far_more_than_one_release_under_a_tries_equals_1_worker(): void
    {
        $job = new ReleasingJob;
        $job->tries = 25;
        app('queue')->connection('database')->push($job);

        // The worker option this app's own composer.json "dev" script uses.
        $options = new WorkerOptions(maxTries: 1);

        // Twenty pops -- twenty releases -- comfortably more than a single
        // rate-limit release would ever cost one job, and still nowhere
        // near the job's own limit of 25.
        for ($i = 0; $i < 20; $i++) {
            $this->worker()->runNextJob('database', 'default', $options);
        }

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_job_with_tries_1_is_killed_by_a_tries_equals_1_worker_on_the_first_release(): void
    {
        // Documents the bug this fix guards against: with the worker's own
        // --tries=1 and no per-job override, a single release is already
        // fatal -- exactly what stranded WhatsappCampaignLog rows at
        // `pending` forever before this fix.
        $job = new ReleasingJob;
        $job->tries = 1;
        app('queue')->connection('database')->push($job);

        $options = new WorkerOptions(maxTries: 1);

        // Pop #1: attempts becomes 1 (<= tries=1, allowed), handle() runs
        // and releases the job back onto the queue.
        $this->worker()->runNextJob('database', 'default', $options);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);

        // Pop #2: attempts becomes 2 (> tries=1) -- Worker kills it via
        // markJobAsFailedIfAlreadyExceedsMaxAttempts() before handle() ever
        // runs again.
        $this->worker()->runNextJob('database', 'default', $options);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
    }
}

/**
 * A minimal queued job that always releases itself -- a stand-in for
 * SendWhatsappCampaignMessage hitting the whatsapp-campaign rate limit,
 * without depending on the real RateLimiter's cache-backed timing. Kept
 * independent of SendWhatsappCampaignMessage (see the class docblock above
 * for why subclassing it does not work here) and named rather than
 * anonymous, since the database queue driver serializes the job to store it
 * in the `jobs` table and PHP refuses to serialize anonymous classes.
 */
class ReleasingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $this->release(0);
    }
}
