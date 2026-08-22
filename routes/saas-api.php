<?php

use App\Http\Controllers\Api\SaasBackupController;
use App\Http\Controllers\Api\SaasConfigController;
use App\Http\Controllers\Api\SaasLicenseController;
use Illuminate\Support\Facades\Route;

/*
 * The backup + license API for client-built software Chakra App Studio
 * maintains -- DJ Thangamaaligai's ERP is the first caller.
 *
 * Registered outside routes/web.php on purpose, same reasoning as
 * routes/mcp.php: no session, no CSRF, a bearer token is the only way in,
 * and it authenticates a SaasProduct, not a person.
 */
Route::prefix('api/saas')->name('api.saas.')->group(function () {
    Route::post('/backups', [SaasBackupController::class, 'store'])->name('backups.store');
    Route::get('/backups', [SaasBackupController::class, 'index'])->name('backups.index');
    Route::get('/backups/{backup}/download', [SaasBackupController::class, 'download'])->name('backups.download');
    Route::get('/license', [SaasLicenseController::class, 'show'])->name('license');
    Route::get('/config', [SaasConfigController::class, 'show'])->name('config');
});
