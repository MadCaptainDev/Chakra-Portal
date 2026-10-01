<?php

namespace App\Http\Controllers;

use App\Support\HomePage;
use Illuminate\View\View;

/**
 * /showreel: the homepage, reachable while signed in.
 *
 * The homepage itself sends signed-in staff to their dashboard, so this is
 * how the studio sees what visitors see. It tells search engines the real
 * address is / (see the canonical link in home.blade.php).
 */
class ShowreelController extends Controller
{
    public function __invoke(): View
    {
        return view('home', HomePage::data());
    }
}
