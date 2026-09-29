<?php

use App\Http\Controllers\VideoCheckController;
use Illuminate\Support\Facades\Route;

/*
 * The Video Checker. Its own file, loaded by AppServiceProvider, rather than
 * a block in web.php -- the module is self-contained and web.php is busy.
 * Ordinary signed-in browser routes: session, CSRF, and the module gate.
 */
Route::middleware(['web', 'auth', 'module:video-check,view'])->group(function () {
    Route::get('video-check', [VideoCheckController::class, 'index'])->name('video-check.index');
    Route::post('video-check', [VideoCheckController::class, 'store'])->name('video-check.store');
});
