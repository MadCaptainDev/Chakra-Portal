<?php

namespace App\Http\Controllers;

use App\Services\StudioMap;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * The Studio Map: the studio drawn as a small 3D town, one building per
 * client -- see App\Services\StudioMap for what each one shows and
 * resources/js/studio-map.js for the drawing. Admin-only and read-only:
 * every building links out to the screen where something can be done.
 */
class StudioMapController extends Controller
{
    public function index(): View
    {
        return view('studio-map.index', ['snapshot' => StudioMap::snapshot()]);
    }

    /** The same snapshot again, for the page's once-a-minute refresh. */
    public function data(): JsonResponse
    {
        return response()->json(StudioMap::snapshot());
    }
}
