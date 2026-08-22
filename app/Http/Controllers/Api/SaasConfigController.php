<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the client-built software itself needs to know about how Chakra
 * Portal has it configured -- distinct from SaasLicenseController, which is
 * about whether it should keep running at all. This is settings, not
 * standing. Read fresh on every call, same as everything else here: an
 * admin changing the retention count in Chakra Portal must not require
 * redeploying the client software to pick it up.
 */
class SaasConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var SaasProduct $product */
        $product = $request->attributes->get('saas_product');

        return response()->json([
            'name' => $product->name,
            'backup_retention_count' => $product->backup_retention_count,
            'amc_frequency' => $product->recurringInvoice?->frequency,
        ]);
    }
}
