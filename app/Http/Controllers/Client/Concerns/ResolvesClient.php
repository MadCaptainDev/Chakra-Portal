<?php

namespace App\Http\Controllers\Client\Concerns;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Whose data this request is about.
 *
 * One place decides, and every client screen goes through it. The alternative
 * -- each controller reaching for $request->user()->client_id itself -- is four
 * chances to forget, and forgetting once shows one client another's invoices.
 */
trait ResolvesClient
{
    protected function client(Request $request): Client
    {
        // The middleware has already refused a login with no client, so this
        // cannot be null by the time a controller runs.
        return $request->user()->client()->firstOrFail();
    }

    /**
     * This client's invoices, and only the ones the studio has actually issued.
     *
     * pending_approval means the invoice is still being written: it has no
     * number yet and the figures may change. Showing one would be quoting a
     * price nobody has agreed to send.
     *
     * @return Builder<Invoice>
     */
    protected function issuedInvoices(Client $client): Builder
    {
        return Invoice::query()
            ->where('client_id', $client->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID]);
    }
}
