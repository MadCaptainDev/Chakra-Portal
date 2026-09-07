<?php

namespace App\Http\Controllers;

use App\Models\Script;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Check from each other" -- a flat review thread on a script. Gated by
 * the `comment` ability already reserved on the scripts module (see
 * App\Support\Permission::MODULES) and unused until now.
 */
class ScriptCommentController extends Controller
{
    public function store(Request $request, Script $script): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $script->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return redirect(route('scripts.show', $script).'#comments');
    }
}
