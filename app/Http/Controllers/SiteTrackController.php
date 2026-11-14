<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Collects the public website's visits and taps (see the site_visits
 * migration). GET with a tiny query so it needs no CSRF token and never
 * holds the page up; it answers 204 whatever happens.
 */
class SiteTrackController extends Controller
{
    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp\/|headless|lighthouse|pingdom|curl|wget|python|axios|httpclient/i';

    public const EVENTS = ['whatsapp', 'play', 'case_study', 'section', 'scroll', 'category', 'more', 'call', 'enquiry'];

    public function hit(Request $request): Response
    {
        if ($this->skip($request)) {
            return response()->noContent();
        }
        $v = $this->ids($request);
        if (! $v) {
            return response()->noContent();
        }

        $ref = (string) $request->query('r', '');
        $host = $ref !== '' ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : '';
        $host = preg_replace('/^www\./', '', $host);
        if ($host === strtolower($request->getHost()) || $host === 'chakragroups.in') {
            $host = ''; // moving around the site is not a source
            $internal = true;
        }

        DB::table('site_visits')->insert([
            'visitor' => $v[0],
            'session' => $v[1],
            'page' => $this->page($request),
            'source' => ($internal ?? false) ? 'internal' : self::source($host, (string) $request->query('us', ''), (string) $request->userAgent()),
            'referrer_host' => $host !== '' ? mb_substr($host, 0, 120) : null,
            'utm_campaign' => $request->filled('uc') ? mb_substr((string) $request->query('uc'), 0, 80) : null,
            'device' => self::device((string) $request->userAgent()),
            'first_visit' => $request->query('n') === '1',
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    public function event(Request $request): Response
    {
        $type = (string) $request->query('t', '');
        if ($this->skip($request) || ! in_array($type, self::EVENTS, true) || ! ($v = $this->ids($request))) {
            return response()->noContent();
        }

        DB::table('site_events')->insert([
            'visitor' => $v[0],
            'session' => $v[1],
            'page' => $this->page($request),
            'type' => $type,
            'target' => $request->filled('g') ? mb_substr((string) $request->query('g'), 0, 160) : null,
            'label' => $request->filled('l') ? mb_substr((string) $request->query('l'), 0, 200) : null,
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    /** Where a visit came from: the campaign tag when there is one, else the referrer, else the in-app browser. */
    public static function source(string $host, string $utm, string $agent): string
    {
        $utm = strtolower(trim($utm));
        if ($utm !== '') {
            return match (true) {
                str_contains($utm, 'insta'), $utm === 'ig' => 'instagram',
                str_contains($utm, 'face'), $utm === 'fb' => 'facebook',
                str_contains($utm, 'whatsapp'), $utm === 'wa' => 'whatsapp',
                str_contains($utm, 'google') => 'google',
                default => mb_substr(preg_replace('/[^a-z0-9_-]/', '', $utm), 0, 24) ?: 'other',
            };
        }

        return match (true) {
            str_contains($host, 'instagram') || str_contains($agent, 'Instagram') => 'instagram',
            str_contains($host, 'facebook') || $host === 'fb.com' || $host === 'm.me' || preg_match('/FBAN|FBAV|FB_IAB/', $agent) === 1 => 'facebook',
            str_contains($host, 'whatsapp') || $host === 'wa.me' => 'whatsapp',
            str_contains($host, 'google') => 'google',
            str_contains($host, 'bing') || str_contains($host, 'duckduckgo') || str_contains($host, 'yahoo') => 'search',
            str_contains($host, 'youtube') => 'youtube',
            str_contains($host, 'linkedin') || $host === 'lnkd.in' => 'linkedin',
            $host === '' => 'direct',
            default => 'other',
        };
    }

    public static function device(string $agent): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|(Android(?!.*Mobile))/i', $agent) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|Android/i', $agent) => 'phone',
            default => 'computer',
        };
    }

    /** Staff and bots are not visitors. */
    private function skip(Request $request): bool
    {
        return $request->user() !== null
            || $request->query('s') === '1' // this browser has been signed in before
            || preg_match(self::BOTS, (string) $request->userAgent()) === 1;
    }

    /** @return array{0: string, 1: string}|null the browser's random ids, checked */
    private function ids(Request $request): ?array
    {
        $v = (string) $request->query('v', '');
        $s = (string) $request->query('ss', '');

        return preg_match('/^[a-z0-9]{16}$/', $v) && preg_match('/^[a-z0-9]{16}$/', $s) ? [$v, $s] : null;
    }

    private function page(Request $request): string
    {
        $p = '/'.ltrim((string) parse_url((string) $request->query('p', '/'), PHP_URL_PATH), '/');

        return mb_substr($p, 0, 160);
    }
}
