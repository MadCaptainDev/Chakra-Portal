<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\EquipmentItem;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootKit;
use App\Models\User;
use App\Support\KitAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShootController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim($request->string('q')->toString()),
            'client' => $request->string('client')->toString(),
            'status' => $request->string('status')->toString(),
            'past' => $request->boolean('past'),
        ];

        $shoots = Shoot::query()
            ->with(['client', 'crew.user', 'kits'])
            ->when($filters['q'] !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('title', 'like', "%{$filters['q']}%")
                    ->orWhere('location', 'like', "%{$filters['q']}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$filters['q']}%"))
            ))
            ->when($filters['client'] !== '', fn ($query) => $query->where('client_id', $filters['client']))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            // Upcoming by default: a planner looks forwards.
            ->when(! $filters['past'], fn ($query) => $query->where('starts_at', '>=', now()->startOfDay()))
            ->orderBy('starts_at', $filters['past'] ? 'desc' : 'asc')
            ->paginate(20)
            ->withQueryString();

        /*
         * Kit still out after a shoot has finished. The single most useful
         * number here -- it is how a studio finds the lens that never came
         * back without walking the cupboard.
         */
        $overdue = ShootKit::query()
            ->whereNotNull('checked_out_at')
            ->whereNull('returned_at')
            ->whereHas('shoot', fn ($query) => $query
                ->where('status', '!=', Shoot::STATUS_CANCELLED)
                ->where('starts_at', '<', now()->startOfDay()))
            ->count();

        return view('shoots.index', [
            'shoots' => $shoots,
            'filters' => $filters,
            'statuses' => Shoot::STATUSES,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'upcomingCount' => Shoot::upcoming()->count(),
            'thisWeek' => Shoot::upcoming()->where('starts_at', '<', now()->endOfWeek())->count(),
            'overdueKit' => $overdue,
        ]);
    }

    public function create(): View
    {
        return view('shoots.create', $this->formData(new Shoot([
            'status' => Shoot::STATUS_PLANNED,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $shoot = new Shoot($this->validated($request));
        $shoot->created_by_id = $request->user()->id;
        $shoot->save();

        return redirect()
            ->route('shoots.show', $shoot)
            ->with('status', 'Shoot planned. Add the crew and the kit.');
    }

    public function show(Shoot $shoot): View
    {
        $shoot->load(['client', 'crew.user', 'kits.item.category', 'kits.checkedOutBy', 'scripts', 'createdBy']);

        // One query for the whole picker, never one per row.
        $committed = KitAvailability::during($shoot);
        $shortfalls = KitAvailability::shortfalls();

        return view('shoots.show', [
            'shoot' => $shoot,
            'committed' => $committed,
            'shortfalls' => $shortfalls,
            'available' => EquipmentItem::active()->with('category')->ordered()->get(),
            'staff' => User::staff()->orderBy('name')->get(['id', 'name']),
            'scripts' => Script::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function edit(Shoot $shoot): View
    {
        return view('shoots.edit', $this->formData($shoot));
    }

    public function update(Request $request, Shoot $shoot): RedirectResponse
    {
        $shoot->update($this->validated($request));

        return redirect()->route('shoots.show', $shoot)->with('status', 'Shoot updated.');
    }

    /**
     * Deleting a shoot is refused while its kit is still out.
     *
     * The kit rows cascade, and they are the only record of who has the camera.
     * Cancelling frees the gear for other shoots without erasing that.
     */
    public function destroy(Shoot $shoot): RedirectResponse
    {
        $stillOut = $shoot->kits()->whereNotNull('checked_out_at')->whereNull('returned_at')->count();

        if ($stillOut > 0) {
            return redirect()
                ->route('shoots.show', $shoot)
                ->with('error', $stillOut.' '.\Str::plural('item', $stillOut).' from this shoot are still out. Check them back in first, or cancel the shoot instead of deleting it.');
        }

        $shoot->delete();

        return redirect()->route('shoots.index')->with('status', 'Shoot deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'starts_at' => ['required', 'date'],
            // A finish before the start is a typo, and the availability window
            // would read as zero-length.
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(Shoot::STATUSES))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Shoot $shoot): array
    {
        return [
            'shoot' => $shoot,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'statuses' => Shoot::STATUSES,
        ];
    }
}
