<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The check-in endpoint a client-built app calls to learn whether it should
 * keep running normally.
 *
 * Chakra Portal can only ever answer truthfully here -- it has no way to
 * reach into a different server and stop a process running there. The
 * client-built software has to call this itself and act on the answer (show
 * the banner, refuse to serve requests once suspended). "message" is meant
 * to be shown verbatim in that software's own UI.
 */
class SaasLicenseController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var SaasProduct $product */
        $product = $request->attributes->get('saas_product');
        $status = $product->status();

        return response()->json([
            'status' => $status,
            'message' => $this->messageFor($status, $product),
            'amc_paid_until' => $product->amc_paid_until?->toDateString(),
        ]);
    }

    private function messageFor(string $status, SaasProduct $product): string
    {
        return match ($status) {
            SaasProduct::STATUS_SUSPENDED => 'This software has been suspended pending payment. Contact Chakra to reinstate it.',
            SaasProduct::STATUS_OVERDUE => 'The annual maintenance contract for this software expired on '
                .$product->amc_paid_until->format('j M Y').'. Please arrange payment to avoid suspension.',
            default => 'Active.',
        };
    }
}
