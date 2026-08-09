<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\TeamMember;
use App\Support\PublicUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The people shown on the public landing page.
 *
 * Names of staff already on the payroll are offered as suggestions so the admin
 * can pick rather than retype, but nothing else crosses over: the website list
 * is its own list, and no salary detail is ever exposed here.
 */
class TeamMemberController extends Controller
{
    public function index(): View
    {
        return view('team.index', [
            'members' => TeamMember::ordered()->get(),
            'staffNames' => Expense::where('type', Expense::TYPE_SALARY)
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = PublicUpload::store($request->file('photo'), 'team');
        }

        TeamMember::create($data);

        return redirect()->route('team.index')->with('status', 'Team member added.');
    }

    public function update(Request $request, TeamMember $team): RedirectResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('photo')) {
            $previous = $team->photo_path;
            $data['photo_path'] = PublicUpload::store($request->file('photo'), 'team');
            PublicUpload::delete($previous);
        }

        $team->update($data);

        return redirect()->route('team.index')->with('status', 'Team member updated.');
    }

    public function destroy(TeamMember $team): RedirectResponse
    {
        PublicUpload::delete($team->photo_path);

        $team->delete();

        return redirect()->route('team.index')->with('status', 'Team member removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);

        unset($data['photo']);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_visible'] = $request->boolean('is_visible');

        return $data;
    }
}
