<?php

use App\Http\Controllers\ShowreelController;
use Illuminate\Support\Facades\Route;

/*
 * The public showreel landing page. Its own file, loaded by
 * AppServiceProvider, for the same reason as routes/video-check.php.
 * Public: signed-in staff can open it too, to see what visitors see.
 */
Route::middleware('web')->get('showreel', ShowreelController::class)->name('showreel');
