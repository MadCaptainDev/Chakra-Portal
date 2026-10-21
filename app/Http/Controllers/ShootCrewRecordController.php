<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CrewShootRecord;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shoots → Crew record: how many shoots each person has been on, month by
 * month, and any one person's full list. Read from the crew lists on past
 * shoots (portal-made, and Notion's "Team" imported by NotionShootImporter).
 */
class ShootCrewRecordController extends Controller
{
    public function index(Request $request): View
    {
        $person = $request->filled('person')
            ? User::query()->whereKey($request->integer('person'))->where('role', '!=', User::ROLE_CLIENT)->firstOrFail()
            : null;

        return view('shoots.crew-record', [
            'person' => $person,
            'team' => $person ? null : CrewShootRecord::team(),
            'shoots' => $person ? CrewShootRecord::shoots($person) : null,
        ]);
    }
}
