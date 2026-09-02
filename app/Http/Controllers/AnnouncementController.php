<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Throwable;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('announcements.index', [
            'announcements' => Announcement::with('author')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = Announcement::create($this->validated($request) + [
            'created_by' => $request->user()->id,
        ]);

        // Fired here and only here -- update() must never re-alert. Not
        // gated on any module permission: every member of staff sees
        // announcements somewhere (the index if granted, my.dashboard if
        // not), so the recipient list is all of staff minus the author.
        if ($announcement->is_active) {
            try {
                Notification::send(
                    User::staff()->where('id', '!=', $announcement->created_by)->get(),
                    new AnnouncementPosted($announcement)
                );
            } catch (Throwable $e) {
                Log::error('Announcement push failed.', [
                    'announcement_id' => $announcement->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('announcements.index')->with('status', 'Announcement posted.');
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));

        return redirect()->route('announcements.index')->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return redirect()->route('announcements.index')->with('status', 'Announcement deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['visible_to_clients'] = $request->boolean('visible_to_clients', false);

        return $data;
    }
}
