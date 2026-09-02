<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A client's own gallery of finished work -- the same pieces and the same
 * visibility rule (PortfolioItem::scopePublished()) the public portfolio at
 * /portfolio uses, narrowed to this one client's own linked items. Each
 * piece opens the same public case-study page (portfolio.detail) rather
 * than a client-portal duplicate of it -- that page is already
 * unauthenticated and already built to read well on its own, and a client
 * clicking through to their own already-public work is not a boundary
 * this app needs to enforce twice.
 */
class PortfolioController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        $client = $this->client($request);

        return view('client.portfolio', [
            'client' => $client,
            'items' => $client->portfolioItems()->published()->ordered()->get(),
        ]);
    }
}
