<?php

namespace App\Http\Controllers;

use App\Support\AdminNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pin or unpin a sidebar row to the person's ★ Favourites. */
class NavFavouriteController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:60']])['key'];
        abort_unless(in_array($key, AdminNav::keys($user), true), 422);

        $favs = collect($user->nav_favourites ?? []);
        $favs = $favs->contains($key) ? $favs->reject(fn ($k) => $k === $key) : $favs->push($key);
        $user->forceFill(['nav_favourites' => $favs->values()->take(12)->all()])->save();

        return response()->json(['favourites' => $user->nav_favourites]);
    }
}
