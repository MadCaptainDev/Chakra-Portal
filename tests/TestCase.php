<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but the in-memory test database.
     *
     * phpunit.xml points the suite at sqlite/:memory:, but those <env> entries
     * are silently ignored when a cached config file exists (bootstrap/cache/
     * config.php), because Laravel then skips re-evaluating env() in the config
     * files. Since this app now caches config to serve over the LAN, running the
     * suite after `config:cache` would point RefreshDatabase at the live MySQL
     * database and wipe real invoices. Fail loudly instead.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // Must run here, not in setUp(): the parent setUp() invokes
        // setUpTraits(), which is where RefreshDatabase migrates. A check
        // after parent::setUp() would fire only once the damage was done.
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Refusing to run tests against [{$connection}/{$database}] instead of sqlite/:memory:. "
                .'This almost always means a cached config is overriding phpunit.xml. '
                .'Run `php artisan config:clear` first (and `php artisan config:cache` again afterwards).'
            );
        }
    }
}
