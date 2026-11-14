<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Support\HomePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** The public Team page: the people switched on in Website → Team page. */
class TeamPageController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        $team = TeamMember::visible()->ordered()->get();
        if ($team->isEmpty()) {
            return redirect()->to(route('home').'#team');
        }

        return view('team', ['team' => $team]);
    }
}
