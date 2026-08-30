<?php

namespace App\Http\Middleware;

use App\Services\RecurringInvoiceRunPipeline;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belt-and-braces catch-up for recurring invoices on a machine that may
 * be switched off when the scheduler would normally fire. The first
 * authenticated request of the day triggers generation; Cache::add()
 * is atomic, so this runs at most once per day regardless of how many
 * requests land concurrently - not once per request.
 */
class EnsureRecurringInvoicesGenerated
{
    public function handle(Request $request, Closure $next): Response
    {
        $claimed = Cache::add('recurring-invoices-generated-on-'.today()->toDateString(), true, now()->addDay());

        if ($claimed) {
            app(RecurringInvoiceRunPipeline::class)->run();
        }

        return $next($request);
    }
}
