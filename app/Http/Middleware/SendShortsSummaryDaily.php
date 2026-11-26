<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Notifications\VentureUpdate;
use App\Support\YoutubeShorts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Once a day, from 10am, the admins get "▶ N YouTube Shorts not posted",
 * per editor (App\Support\YoutubeShorts). Carried by any page visit, after
 * the response, because this host's cron does not fire.
 */
class SendShortsSummaryDaily
{
    public const FROM_HOUR = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (app()->runningUnitTests() || now()->hour < self::FROM_HOUR) {
            return $response;
        }

        if (Cache::add('shorts-summary-'.today()->toDateString(), true, now()->endOfDay())) {
            defer(function () {
                try {
                    self::send();
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $response;
    }

    /** Sends today's summary to every admin; false when nothing is missing. */
    public static function send(): bool
    {
        $summary = YoutubeShorts::summary();
        if (! $summary) {
            return false;
        }

        Notification::send(User::query()->where('role', User::ROLE_ADMIN)->get(),
            new VentureUpdate($summary['title'], $summary['body'], 'youtube-shorts', route('editor-updates').'#youtube-shorts'));

        return true;
    }
}
