<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\NotionIgnoredName;
use App\Models\NotionShoot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything the Notion ↔ Clients screen shows, as one plain array the page
 * script renders from and every action returns fresh.
 *
 * Two Notion lists need connecting to portal clients, and they are separate
 * free text in Notion:
 *   ventures       the Content tables' Venture -- connected to a content
 *                  account (a client can have several), which is what puts
 *                  a reel on that client's dashboard
 *   shoot clients  the Shoots table's Client -- connected straight to a
 *                  portal client
 *
 * Suggestions are only ever suggestions: shown, never applied. They match
 * whole words, never part of a word -- fuzzy matching on this data sent
 * "thinkwithpriya" to Riya and "SVA Golds and Diamonds" to SVA Silks (see
 * ContentAccountController), and a confident wrong answer is worse than none.
 */
class NotionConnections
{
    /** Words too common in business names to say anything about a match. */
    private const STOP_WORDS = [
        'the', 'and', 'of', 'pvt', 'ltd', 'private', 'limited', 'group', 'groups',
        'company', 'companies', 'co', 'inc', 'official', 'personal', 'branding',
        'dr', 'doctor', 'shop', 'store', 'india', 'new',
    ];

    public static function state(): array
    {
        $clients = Client::orderBy('name')->get();
        $accounts = ContentAccount::with('ventures')->orderBy('name')->get();
        $ignoredVentures = NotionIgnoredName::namesFor(NotionIgnoredName::VENTURE);
        $ignoredShoots = NotionIgnoredName::namesFor(NotionIgnoredName::SHOOT_CLIENT);

        $mappedTo = ContentAccountVenture::pluck('content_account_id', 'venture');
        $ventures = self::ventures($mappedTo, $ignoredVentures);
        $shootNames = self::shootNames($ignoredShoots);

        $index = self::index($clients, $accounts);

        foreach ($ventures as &$venture) {
            $venture['suggestions'] = $venture['account_id'] || $venture['ignored']
                ? []
                : self::suggest($venture['name'], $index, $accounts);
        }
        unset($venture);

        foreach ($shootNames as &$shoot) {
            $shoot['suggestions'] = $shoot['client_id'] && ! $shoot['split'] || $shoot['ignored']
                ? []
                : self::suggest($shoot['name'], $index, $accounts, forShoots: true);
        }
        unset($shoot);

        $counted = collect($ventures)->where('ignored', false);
        $connectedItems = $counted->whereNotNull('account_id')->sum('items');
        $totalItems = $counted->sum('items');

        return [
            'clients' => $clients->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => Str::squish($client->name),
                'active' => (bool) $client->is_active,
                'logo' => $client->logo_path && is_file(public_path($client->logo_path))
                    ? ImageVariant::url($client->logo_path, 96, 96)
                    : null,
            ])->values()->all(),
            'accounts' => $accounts->map(fn (ContentAccount $account) => [
                'id' => $account->id,
                'client_id' => $account->client_id,
                'name' => $account->name,
                'targets' => collect(array_keys(ContentAccount::TARGETABLE))
                    ->mapWithKeys(fn ($source) => [$source => $account->targetFor($source)])
                    ->all(),
            ])->values()->all(),
            'ventures' => array_values($ventures),
            'shootNames' => array_values($shootNames),
            'targetable' => ContentAccount::TARGETABLE,
            'stats' => [
                'connected_items' => $connectedItems,
                'total_items' => $totalItems,
                'ventures_waiting' => $counted->whereNull('account_id')->count(),
                'items_waiting' => $totalItems - $connectedItems,
                'shoots_waiting' => collect($shootNames)->where('ignored', false)->sum('unmapped'),
                'shoot_names_waiting' => collect($shootNames)->where('ignored', false)->filter(fn ($s) => $s['unmapped'] > 0 || $s['split'])->count(),
            ],
        ];
    }

    /**
     * Every distinct Venture in the synced content, with what a person needs
     * to recognise it: how much there is, on which platforms, when it was
     * last posted, and a few of its titles.
     */
    private static function ventures(Collection $mappedTo, array $ignored): array
    {
        $rows = ContentItem::query()
            ->selectRaw('venture, source, count(*) as items, max(published_date) as last_date')
            ->whereNotNull('venture')->where('venture', '!=', '')
            ->groupBy('venture', 'source')
            ->get()
            ->groupBy('venture');

        // A few recent titles per venture, newest first.
        $samples = ContentItem::query()
            ->whereNotNull('venture')->where('venture', '!=', '')
            ->whereNotNull('title')->where('title', '!=', '')
            ->orderByDesc('published_date')
            ->get(['venture', 'title'])
            ->groupBy('venture')
            ->map(fn ($items) => $items->pluck('title')->map(fn ($t) => Str::limit(Str::squish($t), 70))->unique()->take(3)->values()->all());

        // A venture that is connected but has no content (renamed in Notion,
        // say) still belongs on its account, so it is listed too.
        $names = $rows->keys()->merge($mappedTo->keys())->unique();

        return $names->map(function (string $name) use ($rows, $samples, $mappedTo, $ignored) {
            $group = $rows->get($name, collect());
            $last = $group->max('last_date');

            return [
                'name' => $name,
                'items' => (int) $group->sum('items'),
                'sources' => $group->pluck('source')->unique()->values()->all(),
                'last_date' => $last ? \Illuminate\Support\Carbon::parse($last)->format('j M Y') : null,
                'samples' => $samples->get($name, []),
                'account_id' => $mappedTo->get($name),
                'ignored' => in_array($name, $ignored, true),
            ];
        })->sortByDesc('items')->values()->all();
    }

    /**
     * Every distinct Client name on Notion shoots. One name can have been
     * filed under two clients, or be part-filed -- shoots synced after the
     * name was mapped -- and both are shown as "split" so they get fixed.
     */
    private static function shootNames(array $ignored): array
    {
        return NotionShoot::query()
            ->selectRaw('client, client_id, count(*) as shoots')
            ->whereNotNull('client')->where('client', '!=', '')
            ->groupBy('client', 'client_id')
            ->get()
            ->groupBy('client')
            ->map(function (Collection $rows, string $name) use ($ignored) {
                $mapped = $rows->whereNotNull('client_id');
                $unmapped = (int) $rows->whereNull('client_id')->sum('shoots');
                // The client most of its shoots are filed under is what the
                // name means; anything else is the split to fix.
                $main = $mapped->sortByDesc('shoots')->first();

                return [
                    'name' => $name,
                    'shoots' => (int) $rows->sum('shoots'),
                    'client_id' => $main?->client_id,
                    'unmapped' => $unmapped,
                    'split' => $mapped->count() > 1 || ($main && $unmapped > 0),
                    'breakdown' => $mapped->map(fn ($r) => ['client_id' => $r->client_id, 'shoots' => (int) $r->shoots])->values()->all(),
                    'ignored' => in_array($name, $ignored, true),
                ];
            })
            ->sortByDesc('shoots')
            ->values()
            ->all();
    }

    /**
     * Every name a client is known by, folded: the client's own name, its
     * notion_venture alias, its accounts' names and their ventures.
     *
     * @return array<int, array{names: list<string>, tokens: list<string>}>
     */
    private static function index(Collection $clients, Collection $accounts): array
    {
        $index = [];

        foreach ($clients as $client) {
            $index[$client->id] = ['names' => array_filter([self::fold($client->name), self::fold($client->notion_venture)])];
        }

        foreach ($accounts as $account) {
            if (! isset($index[$account->client_id])) {
                continue;
            }
            $index[$account->client_id]['names'][] = self::fold($account->name);
            foreach ($account->ventures as $venture) {
                $index[$account->client_id]['names'][] = self::fold($venture->venture);
            }
        }

        foreach ($index as $id => $entry) {
            $names = array_values(array_unique(array_filter($entry['names'])));
            $index[$id] = [
                'names' => $names,
                'tokens' => array_values(array_unique(array_merge(...array_map([self::class, 'tokens'], $names ?: [''])))),
            ];
        }

        return $index;
    }

    /**
     * Up to three likely clients for a Notion name, strongest first.
     *
     * Strong: the same name (ignoring case, spacing and punctuation, or
     * written as one word). Good: every distinctive word of one appears as
     * a whole word in the other. Weak: one distinctive word in common --
     * marked ambiguous when that word belongs to more than one client.
     *
     * @return list<array{client_id: int, account_id: ?int, strength: string, reason: string}>
     */
    public static function suggest(string $name, array $index, Collection $accounts, bool $forShoots = false): array
    {
        $folded = self::fold($name);
        $compact = str_replace(' ', '', $folded);
        $words = self::tokens($folded);

        // How many clients each word belongs to, to tell a distinctive word
        // ("thillai") from a shared one ("sva").
        $owners = [];
        foreach ($index as $clientId => $entry) {
            foreach ($entry['tokens'] as $token) {
                $owners[$token][] = $clientId;
            }
        }

        $found = [];

        foreach ($index as $clientId => $entry) {
            $score = 0;
            $reason = '';

            foreach ($entry['names'] as $known) {
                if ($known === $folded || str_replace(' ', '', $known) === $compact) {
                    $score = 3;
                    $reason = 'Same name';
                    break;
                }
            }

            // Written as one word: "ThillaiPets" is the start of "Thillai Pets
            // Clinic" run together. Two words at least -- one word joined to
            // nothing is just the word, which the checks below handle.
            if ($score === 0 && ! str_contains($folded, ' ') && strlen($compact) >= 6) {
                foreach ($entry['names'] as $known) {
                    $parts = explode(' ', $known);
                    for ($k = 2; $k <= count($parts); $k++) {
                        if (implode('', array_slice($parts, 0, $k)) === $compact) {
                            $score = 2;
                            $reason = 'Same words, written together';
                            break 2;
                        }
                    }
                }
            }

            if ($score === 0 && $words) {
                $shared = array_values(array_intersect($words, $entry['tokens']));
                $knownWords = array_unique(array_merge(...array_map([self::class, 'tokens'], $entry['names'] ?: [''])));

                if ($shared && (count($shared) === count($words) || count($shared) === count($knownWords))) {
                    $score = 2;
                    $reason = 'Same words: '.implode(', ', $shared);
                } elseif ($shared) {
                    $score = 1;
                    $reason = 'Shares “'.$shared[0].'”';
                    if (count($owners[$shared[0]] ?? []) > 1) {
                        $reason .= ' — also other clients';
                    }
                }
            }

            if ($score > 0) {
                $found[] = ['client_id' => $clientId, 'score' => $score, 'reason' => $reason, 'rank' => $score * 10 + count(array_intersect($words, $entry['tokens']))];
            }
        }

        usort($found, fn ($a, $b) => $b['rank'] <=> $a['rank']);

        return collect($found)->take(3)->map(function ($hit) use ($accounts, $words, $forShoots) {
            $accountId = null;

            if (! $forShoots) {
                // The client's account whose name shares the most words with
                // the venture; with one account, that one; with none, a new
                // account is what the button will offer.
                $candidates = $accounts->where('client_id', $hit['client_id']);
                $accountId = $candidates->sortByDesc(fn ($a) => count(array_intersect($words, self::tokens(self::fold($a->name)))))->first()?->id;
            }

            return [
                'client_id' => $hit['client_id'],
                'account_id' => $accountId,
                'strength' => [3 => 'strong', 2 => 'good', 1 => 'weak'][$hit['score']],
                'reason' => $hit['reason'],
            ];
        })->values()->all();
    }

    /** Lowercase, punctuation to spaces, single-spaced: "Dr.Revanth" → "dr revanth". */
    public static function fold(?string $value): string
    {
        $value = Str::lower(Str::ascii((string) $value));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $value)));
    }

    /** @return list<string> the distinctive whole words of a folded name, plurals made singular ("golds" → "gold") */
    private static function tokens(string $folded): array
    {
        $words = array_filter(
            explode(' ', $folded),
            fn ($word) => strlen($word) >= 3 && ! in_array($word, self::STOP_WORDS, true),
        );

        return array_values(array_unique(array_map(
            fn ($word) => strlen($word) > 4 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') ? substr($word, 0, -1) : $word,
            $words,
        )));
    }
}
