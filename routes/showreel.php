<?php

use App\Http\Controllers\ShowreelController;
use Illuminate\Support\Facades\Route;

/*
 * The public showreel landing page. Its own file, loaded by
 * AppServiceProvider, so route:cache still picks it up.
 * Public: signed-in staff can open it too, to see what visitors see.
 */
Route::middleware('web')->get('showreel', ShowreelController::class)->name('showreel');
