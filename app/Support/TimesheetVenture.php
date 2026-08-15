<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Client ↔ timesheet venture is one concept.
 *
 * Selectable values come only from the clients table (prefer notion_venture,
 * else name). Historical free-text venture strings are normalised onto that
 * list; junk that cannot be mapped confidently becomes null.
 */
class TimesheetVenture
{
    /**
     * Not a real client — covers work that spans several clients in one
     * sitting (e.g. a shared shoot) or isn't billable to any single one.
     */
    public const ALL_CLIENTS = 'All / Multiple Clients';

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $clientOptions = self::clients()
            ->map(function (Client $client) {
                $value = self::canonicalForClient($client);
                if ($value === null) {
                    return null;
                }

                $name = trim((string) $client->name);
                $notion = filled($client->notion_venture)
                    ? trim((string) $client->notion_venture)
                    : null;

                $label = $notion !== null && strcasecmp($notion, $name) !== 0
                    ? $name.' · '.$notion
                    : $name;

                return [
                    'value' => $value,
                    'label' => $label,
                ];
            })
            ->filter()
            ->unique(fn (array $option) => mb_strtolower($option['value']))
            ->sortBy(fn (array $option) => mb_strtolower($option['label']), SORT_NATURAL)
            ->values();

        return $clientOptions
            ->push(['value' => self::ALL_CLIENTS, 'label' => self::ALL_CLIENTS])
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function allowedValues(): array
    {
        return array_values(array_map(
            fn (array $option) => $option['value'],
            self::options()
        ));
    }

    /**
     * @return list<string|\Illuminate\Validation\Rules\In>
     */
    public static function validationRules(): array
    {
        return ['required', 'string', 'max:255', Rule::in(self::allowedValues())];
    }

    /**
     * Map a messy historical venture string onto a canonical client label.
     * Returns null when there is no confident client match.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        $trimmed = trim($trimmed, " \t-/");
        if ($trimmed === '') {
            return null;
        }

        $haystack = self::fold($trimmed);

        if (self::fold(self::ALL_CLIENTS) === $haystack
            || in_array($haystack, ['all', 'multiple', 'multiple clients', 'various', 'various clients', 'all clients'], true)) {
            return self::ALL_CLIENTS;
        }
        $clients = self::clients()
            ->map(fn (Client $client) => [
                'canonical' => self::canonicalForClient($client),
                'name' => trim((string) $client->name),
                'notion' => filled($client->notion_venture)
                    ? trim((string) $client->notion_venture)
                    : null,
            ])
            ->filter(fn (array $row) => $row['canonical'] !== null)
            ->values();

        // Exact match against canonical / name / notion_venture.
        foreach ($clients as $client) {
            foreach ([$client['canonical'], $client['name'], $client['notion']] as $candidate) {
                if ($candidate !== null && self::fold((string) $candidate) === $haystack) {
                    return $client['canonical'];
                }
            }
        }

        // Specific aliases before generic contains-matching (order matters).
        $aliasCanonical = self::matchAlias($haystack, $clients);
        if ($aliasCanonical !== null) {
            return $aliasCanonical;
        }

        // Prefix / token match: "Janet - Sperm Kit", "SVA / RED-SAREE".
        $matched = self::matchClientToken($haystack, $clients);
        if ($matched !== null) {
            return $matched;
        }

        return null;
    }

    public static function canonicalForClient(Client $client): ?string
    {
        $value = filled($client->notion_venture)
            ? trim((string) $client->notion_venture)
            : trim((string) $client->name);

        return $value === '' ? null : $value;
    }

    /** Where the request-lifetime memo of the client list lives. */
    private const CLIENT_CACHE = 'timesheet-venture.clients';

    /**
     * Drop the memo. For anything that edits a client and then asks about
     * ventures in the same request.
     */
    public static function forgetClients(): void
    {
        app()->forgetInstance(self::CLIENT_CACHE);
    }

    /**
     * Every raw `content_items.venture` spelling that belongs to this client.
     *
     * The planner is free text, so one client is "SVA Silks", "Sva womenswear"
     * and "SVA / RED-SAREE". Each distinct spelling is put through the same
     * normaliser the timesheet uses and kept if it lands on this client.
     *
     * @return list<string>
     */
    public static function rawVenturesFor(Client $client): array
    {
        $canonical = self::canonicalForClient($client);

        if ($canonical === null) {
            return [];
        }

        return ContentItem::query()
            ->whereNotNull('venture')
            ->where('venture', '!=', '')
            ->distinct()
            ->pluck('venture')
            ->filter(fn (string $venture) => self::fold((string) self::normalize($venture)) === self::fold($canonical))
            ->values()
            ->all();
    }

    /**
     * The client list, read once per request.
     *
     * normalize() consults this on every call, and rawVenturesFor() calls
     * normalize() once per distinct venture string -- two dozen queries against
     * a table of thirteen rows. Memoised in the container rather than a static
     * property on purpose: the container is rebuilt for every test, where a
     * static would carry one test's clients into the next one.
     *
     * @return Collection<int, Client>
     */
    private static function clients(): Collection
    {
        if (! app()->bound(self::CLIENT_CACHE)) {
            app()->instance(self::CLIENT_CACHE, Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'notion_venture']));
        }

        return app()->make(self::CLIENT_CACHE);
    }

    /**
     * @param  Collection<int, array{canonical: string, name: string, notion: ?string}>  $clients
     */
    private static function matchAlias(string $haystack, Collection $clients): ?string
    {
        $find = function (string $needle) use ($clients): ?string {
            $needle = self::fold($needle);
            foreach ($clients as $client) {
                if (self::fold($client['canonical']) === $needle) {
                    return $client['canonical'];
                }
                if (self::fold($client['name']) === $needle) {
                    return $client['canonical'];
                }
                if ($client['notion'] !== null && self::fold($client['notion']) === $needle) {
                    return $client['canonical'];
                }
            }

            // Fallback: canonical containing the needle uniquely, or name contains.
            $hits = $clients->filter(function (array $client) use ($needle) {
                return str_contains(self::fold($client['canonical']), $needle)
                    || str_contains(self::fold($client['name']), $needle)
                    || ($client['notion'] !== null && str_contains(self::fold($client['notion']), $needle));
            });

            return $hits->count() === 1 ? $hits->first()['canonical'] : null;
        };

        // Jewels before generic SVA / SW silks.
        if (preg_match('/\b(sva\s*jewell?s?|sva\s*jw|sva\s*jewel)\b/u', $haystack)
            || preg_match('/(^|[^a-z])sj([^a-z]|$)/u', $haystack)) {
            return $find('SVA Jewells') ?? $find('sva jewells');
        }

        if (preg_match('/\b(azhagar|alaghar|alagar)\b/u', $haystack)) {
            return $find('Sri Azhagar Thanga Maligai');
        }

        if (preg_match('/\b(jann?et|janetn)\b/u', $haystack)) {
            return $find('Janet');
        }

        if (preg_match('/(^|[^a-z])dj([^a-z]|$)/u', $haystack)) {
            return $find('DJ') ?? $find('DJ THANGA MAALIGAI');
        }

        if (preg_match('/\briya\b/u', $haystack)) {
            return $find('Riya');
        }

        if (preg_match('/\b(surya.?s|suryas|suray.?s)\b/u', $haystack)) {
            return $find("Surya's Restaurant") ?? $find('Suryas');
        }

        if (preg_match('/\bthor\b/u', $haystack)) {
            return $find('Thor Gym') ?? $find('THOR');
        }

        // Vinu / Vinupriya — not "Venu" (different brand in historical notes).
        if (preg_match('/\b(vinu|vinupriya|vnupriya)\b/u', $haystack)) {
            return $find('Vinupriya - Personal Branding') ?? $find('Vinu');
        }

        if (
            (preg_match('/\b(sda|sdm)\b/u', $haystack) && preg_match('/mobile/u', $haystack))
            || preg_match('/\bsda mobiles\b/u', $haystack)
            || $haystack === 'sda mobiles'
            || $haystack === 'sdm mobiles'
        ) {
            return $find('SDA Mobiles');
        }

        if (preg_match('/\bkanishka\b/u', $haystack)) {
            return $find('Kanishka');
        }

        if (preg_match('/\bkrithika\b/u', $haystack)) {
            return $find('Krithika');
        }

        // SW / SVA Silks / Sva womenswear → silks client (not jewels).
        if (preg_match('/\b(sva\s*silks|sva\s*womenswear|sva\s*women.?s\s*wear)\b/u', $haystack)
            || preg_match('/(^|[^a-z])sw([^a-z]|$)/u', $haystack)) {
            return $find('SVA Silks')
                ?? $find('SVA Silks and Readymades')
                ?? $find('Sva womenswear');
        }

        if (preg_match('/\bsva\b/u', $haystack)) {
            return $find('SVA Silks')
                ?? $find('SVA Silks and Readymades')
                ?? $find('SVA');
        }

        return null;
    }

    /**
     * @param  Collection<int, array{canonical: string, name: string, notion: ?string}>  $clients
     */
    private static function matchClientToken(string $haystack, Collection $clients): ?string
    {
        $best = null;
        $bestLen = 0;

        foreach ($clients as $client) {
            foreach ([$client['notion'], $client['canonical'], $client['name']] as $label) {
                if ($label === null || $label === '') {
                    continue;
                }
                $folded = self::fold($label);
                if ($folded === '' || mb_strlen($folded) < 2) {
                    continue;
                }
                if (! str_contains($haystack, $folded)) {
                    continue;
                }
                // Prefer the longest label so "SVA Jewells" beats "SVA".
                if (mb_strlen($folded) > $bestLen) {
                    $bestLen = mb_strlen($folded);
                    $best = $client['canonical'];
                }
            }
        }

        return $best;
    }

    private static function fold(string $value): string
    {
        $value = str_replace(
            ["\u{2019}", "\u{2018}", "\u{02BC}", '`'],
            "'",
            $value
        );
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
