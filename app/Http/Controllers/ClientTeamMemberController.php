<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Who a client's own dashboard names as "Your team" -- see
 * client_team_members's own migration for why this is a pivot rather than
 * fixed columns on Client. Behind clients,manage, the same bar as issuing
 * a client login: both put something staff-only in front of a client.
 */
class ClientTeamMemberController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->findOrFail($data['user_id']);

        $client->teamMembers()->syncWithoutDetaching([
            $user->id => ['role' => $data['role'] ?? null],
        ]);

        return redirect()->route('clients.show', $client)->with('status', "{$user->name} added to {$client->name}'s team.");
    }

    public function destroy(Client $client, User $teamMember): RedirectResponse
    {
        $client->teamMembers()->detach($teamMember->id);

        return redirect()->route('clients.show', $client)->with('status', "{$teamMember->name} removed from {$client->name}'s team.");
    }
}
