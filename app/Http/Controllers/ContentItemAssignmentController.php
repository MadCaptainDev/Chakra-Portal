<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The one write action on an otherwise read-only board: who owns this
 * piece. Separate, tiny controller rather than a method on
 * ContentDashboardController, since that one is deliberately read-only
 * over the Notion cache everywhere else -- see its own doc block.
 */
class ContentItemAssignmentController extends Controller
{
    public function update(Request $request, ContentItem $contentItem): RedirectResponse
    {
        $data = $request->validate([
            'assigned_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $contentItem->forceFill(['assigned_user_id' => $data['assigned_user_id'] ?? null])->save();

        return back();
    }
}
