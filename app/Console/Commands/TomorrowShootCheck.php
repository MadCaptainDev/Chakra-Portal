<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TomorrowShootsReport;
use App\Support\ShootReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Six in the evening: are tomorrow's shoots ready? One message to the
 * admins naming every shoot that is not -- client not confirmed, content
 * not ready, no crew, no kit, no location or time -- while there is still
 * an evening to fix it. Sent once a day, and also when every shoot is fine,
 * so silence never has to be read as good news.
 */
class TomorrowShootCheck extends Command
{
    protected $signature = 'shoots:tomorrow-check {--force : Send even if today\'s already went} {--dry : Print it, send nothing}';

    protected $description = 'Tell admins at 6 PM which of tomorrow\'s shoots are not ready.';

    public function handle(): int
    {
        $tomorrow = now()->addDay();
        $rows = ShootReadiness::forDay($tomorrow);

        if ($rows->isEmpty()) {
            $this->info('No shoots tomorrow.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $message = (new TomorrowShootsReport($tomorrow, $rows))->toFcm(new User);
            $this->line($message->title);
            $this->line($message->body);

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! Cache::add('shoots:tomorrow-check:'.today()->toDateString(), true, now()->addDays(2))) {
            $this->info('Already sent today.');

            return self::SUCCESS;
        }

        $problems = $rows->filter(fn (array $r) => $r['problems'] !== []);
        Notification::send(User::query()->where('role', User::ROLE_ADMIN)->get(), new TomorrowShootsReport($tomorrow, $rows));

        $this->info($rows->count().' shoot(s) tomorrow, '.$problems->count().' not ready.');

        return self::SUCCESS;
    }
}
