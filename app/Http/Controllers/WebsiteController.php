<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Support\PublicUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Website: who visits the public site and what they do there (from
 * SiteTrackController's own counting -- there was nothing before
 * 15 Nov 2026), and the people on the public Team page. Admins only.
 */
class WebsiteController extends Controller
{
    public function index(Request $request): View
    {
        $days = in_array((int) $request->query('days'), [1, 7, 30, 90], true) ? (int) $request->query('days') : 30;
        $from = today()->subDays($days - 1)->startOfDay();

        $visits = DB::table('site_visits')->where('created_at', '>=', $from);
        $events = DB::table('site_events')->where('created_at', '>=', $from);

        // One row per sitting: where it started, and on what.
        $sessions = (clone $visits)->where('source', '!=', 'internal')
            ->selectRaw('session, MIN(id) AS first')->groupBy('session')->pluck('first');
        $starts = DB::table('site_visits')->whereIn('id', $sessions)->get(['session', 'visitor', 'source', 'page', 'device', 'utm_campaign', 'referrer_host', 'first_visit', 'created_at']);
        $sessionCount = max(1, $starts->count());

        $wa = (clone $events)->where('type', 'whatsapp')->where('target', '!=', 'opened')->get();
        $waSessions = $wa->pluck('session')->unique()->count();
        $chats = DB::table('whatsapp_webhook_events')->where('type', 'message')->where('occurred_at', '>=', $from)
            ->where('summary', 'like', '%via chakragroups.in%')->distinct()->count('wa_id');

        $homeSessions = (clone $visits)->where('page', '/')->distinct()->count('session');
        $sections = (clone $events)->where('type', 'section')->where('page', '/')
            ->selectRaw('target, COUNT(DISTINCT session) AS n')->groupBy('target')->pluck('n', 'target');

        return view('website.index', [
            'days' => $days,
            'since' => DB::table('site_visits')->min('created_at'),
            'totals' => [
                'visitors' => (clone $visits)->distinct()->count('visitor'),
                'new' => $starts->where('first_visit', true)->count(),
                'sessions' => $starts->count(),
                'views' => (clone $visits)->count(),
                'whatsapp' => $waSessions,
                'chats' => $chats,
            ],
            'daily' => $this->daily($from, $days),
            'sources' => $starts->groupBy('source')->map->count()->sortDesc(),
            'devices' => $starts->groupBy('device')->map->count()->sortDesc(),
            'landing' => $starts->groupBy('page')->map->count()->sortDesc()->take(8),
            'pages' => (clone $visits)->selectRaw('page, COUNT(*) AS n')->groupBy('page')->orderByDesc('n')->limit(10)->pluck('n', 'page'),
            'campaigns' => $starts->whereNotNull('utm_campaign')->groupBy('utm_campaign')->map->count()->sortDesc()->take(8),
            'referrers' => $starts->whereNotNull('referrer_host')->groupBy('referrer_host')->map->count()->sortDesc()->take(8),
            'waChoices' => $wa->groupBy('target')->map->count()->sortDesc(),
            'waFrom' => $wa->groupBy('page')->map->count()->sortDesc()->take(6),
            'waLiked' => $wa->where('target', 'like_this')->groupBy('label')->map->count()->sortDesc()->take(6),
            'reels' => (clone $events)->whereIn('type', ['play', 'case_study'])
                ->selectRaw("target, MAX(label) AS label, SUM(type = 'play') AS plays, SUM(type = 'case_study') AS opens, COUNT(DISTINCT session) AS people")
                ->groupBy('target')->orderByDesc('people')->limit(10)->get(),
            'sections' => collect(['services' => 'What we do', 'work' => 'The work', 'team' => 'The team', 'contact' => 'Contact'])
                ->map(fn ($label, $id) => ['label' => $label, 'n' => (int) ($sections[$id] ?? 0), 'pct' => $homeSessions ? round(($sections[$id] ?? 0) / $homeSessions * 100) : 0])
                ->merge($sections->except(['services', 'work', 'team', 'contact'])->map(fn ($n, $id) => ['label' => ucfirst($id), 'n' => (int) $n, 'pct' => $homeSessions ? round($n / $homeSessions * 100) : 0])),
            'homeSessions' => $homeSessions,
            'scroll' => (clone $events)->where('type', 'scroll')->selectRaw('target, COUNT(DISTINCT session) AS n')->groupBy('target')->pluck('n', 'target'),
            'categories' => (clone $events)->where('type', 'category')->selectRaw('target, COUNT(*) AS n')->groupBy('target')->orderByDesc('n')->limit(8)->pluck('n', 'target'),
            'sessionCount' => $sessionCount,
            'team' => TeamMember::query()->ordered()->get(),
        ]);
    }

    /** @return list<array{date: Carbon, visitors: int, whatsapp: int}> */
    private function daily(Carbon $from, int $days): array
    {
        $v = DB::table('site_visits')->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) AS d, COUNT(DISTINCT visitor) AS n')->groupBy('d')->pluck('n', 'd');
        $w = DB::table('site_events')->where('created_at', '>=', $from)->where('type', 'whatsapp')->where('target', '!=', 'opened')
            ->selectRaw('DATE(created_at) AS d, COUNT(DISTINCT session) AS n')->groupBy('d')->pluck('n', 'd');

        return collect(range(0, max(0, $days - 1)))->map(function ($i) use ($from, $v, $w) {
            $d = $from->copy()->addDays($i);

            return ['date' => $d, 'visitors' => (int) ($v[$d->toDateString()] ?? 0), 'whatsapp' => (int) ($w[$d->toDateString()] ?? 0)];
        })->all();
    }

    // ——— The Team page ———

    public function storeMember(Request $request): RedirectResponse
    {
        $data = $this->member($request);
        $data['sort_order'] = (int) TeamMember::max('sort_order') + 1;
        TeamMember::create($data);

        return redirect()->route('website.index', ['tab' => 'team'])->with('status', 'Added to the Team page (hidden until you switch them on).');
    }

    public function updateMember(Request $request, TeamMember $member): RedirectResponse
    {
        $data = $this->member($request, $member);
        $member->update($data);

        return redirect()->route('website.index', ['tab' => 'team'])->with('status', $member->name.' saved.');
    }

    public function toggleMember(TeamMember $member): RedirectResponse
    {
        $member->update(['is_visible' => ! $member->is_visible]);

        return redirect()->route('website.index', ['tab' => 'team'])
            ->with('status', $member->name.($member->is_visible ? ' is now on the Team page.' : ' is hidden from the Team page.'));
    }

    public function moveMember(Request $request, TeamMember $member): RedirectResponse
    {
        $all = TeamMember::query()->ordered()->get()->values();
        $i = $all->search(fn ($m) => $m->id === $member->id);
        $j = $request->input('to') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($all[$j])) {
            [$all[$i], $all[$j]] = [$all[$j], $all[$i]];
            $all->each(fn ($m, $n) => $m->forceFill(['sort_order' => $n + 1])->save());
        }

        return redirect()->route('website.index', ['tab' => 'team']);
    }

    public function destroyMember(TeamMember $member): RedirectResponse
    {
        $member->delete();

        return redirect()->route('website.index', ['tab' => 'team'])->with('status', $member->name.' removed from the Team page.');
    }

    /** @return array<string, mixed> */
    private function member(Request $request, ?TeamMember $member = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $out = ['name' => trim($data['name']), 'role' => $data['role'] ?? null, 'bio' => $data['bio'] ?? null];
        if ($request->hasFile('photo')) {
            // A photo uploaded here is the Team page's own; a portal avatar it replaces is left alone.
            $out['photo_path'] = PublicUpload::store($request->file('photo'), 'team');
        }

        return $out;
    }
}
