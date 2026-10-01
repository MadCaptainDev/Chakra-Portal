<?php

namespace App\Http\Controllers;

use App\Support\HomePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The public homepage: the scroll-driven showreel film, then the landing page
 * (resources/views/home.blade.php, data from App\Support\HomePage).
 *
 * Signed-in staff never see it -- they go to whichever home their role has;
 * /showreel shows them the same page.
 */
class LandingController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route(auth()->user()->homeRoute());
        }

        return view('home', HomePage::data());
    }
}
