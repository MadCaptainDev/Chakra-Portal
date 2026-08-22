<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasBackup;
use App\Models\SaasProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Receiving, listing and serving backup files for one authenticated
 * SaasProduct. AuthenticateSaasProduct has already resolved the token by
 * the time any method here runs -- see routes/saas-api.php.
 */
class SaasBackupController extends Controller
{
    /** Generous, but bounded -- a runaway upload should fail loudly, not fill the disk silently. 1 GB. */
    private const MAX_KILOBYTES = 1_048_576;

    public function store(Request $request): JsonResponse
    {
        $product = $this->product($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
            // What the ERP says the backup was taken at -- defaults to now()
            // if the caller does not send one, so this stays optional rather
            // than forcing every integration to compute it.
            'taken_at' => ['nullable', 'date'],
        ]);

        $file = $validated['file'];
        $checksum = hash_file('sha256', $file->getRealPath());
        $takenAt = isset($validated['taken_at']) ? Carbon::parse($validated['taken_at']) : now();

        // Named by product + timestamp rather than the client's own filename
        // (which a hostile or buggy caller controls) -- keeps the path
        // predictable and never trusts external input for where a file lands.
        $filename = $takenAt->format('Y-m-d_His').'_'.$checksum.'.'.($file->getClientOriginalExtension() ?: 'bin');
        $diskPath = $file->storeAs("saas-backups/{$product->id}", $filename, 'local');

        $backup = SaasBackup::create([
            'saas_product_id' => $product->id,
            'disk_path' => $diskPath,
            'size_bytes' => $file->getSize(),
            'original_filename' => $file->getClientOriginalName(),
            'checksum' => $checksum,
            'taken_at' => $takenAt,
        ]);

        $this->pruneOldest($product);

        return response()->json([
            'id' => $backup->id,
            'taken_at' => $backup->taken_at->toIso8601String(),
            'size_bytes' => $backup->size_bytes,
            'checksum' => $backup->checksum,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $product = $this->product($request);

        $backups = $product->backups()->newestFirst()->get(['id', 'taken_at', 'size_bytes', 'checksum']);

        return response()->json(['data' => $backups]);
    }

    /**
     * 404, not 403, for a backup belonging to a different product -- a
     * token has no business even learning that a specific id exists, same
     * stance McpTokenController::destroy() takes with someone else's token.
     */
    public function download(Request $request, int $backup)
    {
        $product = $this->product($request);

        // Looked up by hand rather than via implicit route-model binding:
        // this route sits outside the web/api middleware groups (no
        // session, same as everywhere else in this file), so the
        // SubstituteBindings middleware that normally does this binding
        // never runs and the container would otherwise hand the controller
        // an empty, unfetched model. findOrFail() gets us the same 404 an
        // unresolved binding would have given, on purpose.
        $backup = SaasBackup::findOrFail($backup);

        abort_unless($backup->saas_product_id === $product->id, 404);
        abort_unless(Storage::disk('local')->exists($backup->disk_path), 404);

        return Storage::disk('local')->download($backup->disk_path, $backup->original_filename ?: 'backup.bin');
    }

    /**
     * Delete the oldest backups (row and file both) once this product has
     * more than its own backup_retention_count. Synchronous, on the same
     * request that just uploaded one -- there is no reliable cron on this
     * host to hand a scheduled prune off to, and this needs none: it is
     * self-cleaning on every write.
     */
    private function pruneOldest(SaasProduct $product): void
    {
        $excess = $product->backups()->count() - $product->backup_retention_count;

        if ($excess <= 0) {
            return;
        }

        $product->backups()->oldest('taken_at')->limit($excess)->get()->each(function (SaasBackup $old) {
            Storage::disk('local')->delete($old->disk_path);
            $old->delete();
        });
    }

    private function product(Request $request): SaasProduct
    {
        return $request->attributes->get('saas_product');
    }
}
