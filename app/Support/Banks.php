<?php

namespace App\Support;

/**
 * The banks an EMI can be paid through, with their logos.
 *
 * Logos are the banks' own, taken from each bank's Wikipedia infobox (Fi's
 * from fi.money) and stored as PNG in public/images/banks -- PNG rather than
 * SVG so nothing scriptable is ever served from our own origin.
 *
 * Aliases are what the team actually typed into the old free-text "Bank"
 * box (BOI, CANA, FI, "FEDERAL - Annamalai"), so those rows can be read back
 * into a bank and an account holder.
 */
class Banks
{
    /** key => [name, aliases (lower case)] */
    public const ALL = [
        'sbi' => ['State Bank of India', ['sbi', 'state bank', 'state bank of india']],
        'hdfc' => ['HDFC Bank', ['hdfc', 'hdfc bank']],
        'icici' => ['ICICI Bank', ['icici', 'icici bank']],
        'axis' => ['Axis Bank', ['axis', 'axis bank']],
        'kotak' => ['Kotak Mahindra Bank', ['kotak', 'kotak mahindra', 'kotak mahindra bank']],
        'boi' => ['Bank of India', ['boi', 'bank of india']],
        'canara' => ['Canara Bank', ['cana', 'canara', 'canara bank']],
        'federal' => ['Federal Bank', ['federal', 'federal bank']],
        'fi' => ['Fi', ['fi', 'fi money', 'epifi']],
        'indian' => ['Indian Bank', ['indian bank']],
        'iob' => ['Indian Overseas Bank', ['iob', 'indian overseas', 'indian overseas bank']],
        'pnb' => ['Punjab National Bank', ['pnb', 'punjab national', 'punjab national bank']],
        'bob' => ['Bank of Baroda', ['bob', 'baroda', 'bank of baroda']],
        'union' => ['Union Bank of India', ['union', 'union bank', 'union bank of india']],
        'idfc' => ['IDFC FIRST Bank', ['idfc', 'idfc first', 'idfc first bank']],
        'indusind' => ['IndusInd Bank', ['indusind', 'indusind bank']],
        'yes' => ['Yes Bank', ['yes bank']],
        'au' => ['AU Small Finance Bank', ['au bank', 'au small finance', 'au small finance bank']],
        'cub' => ['City Union Bank', ['cub', 'city union', 'city union bank']],
        'kvb' => ['Karur Vysya Bank', ['kvb', 'karur vysya', 'karur vysya bank']],
        'tmb' => ['Tamilnad Mercantile Bank', ['tmb', 'tamilnad mercantile', 'tamilnad mercantile bank']],
        'sib' => ['South Indian Bank', ['sib', 'south indian bank']],
        'bajaj' => ['Bajaj Finance', ['bajaj', 'bajaj finance', 'bajaj finserv']],
    ];

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::ALL[$key]);
    }

    public static function name(?string $key): ?string
    {
        return self::ALL[$key][0] ?? null;
    }

    public static function logo(?string $key): ?string
    {
        return self::exists($key) ? asset('images/banks/'.$key.'.png') : null;
    }

    /** @return array<string, string> key => name, in the order people pick from */
    public static function options(): array
    {
        return array_map(fn ($b) => $b[0], self::ALL);
    }

    /** The bank a word or phrase names ("CANA", "axis bank"), or null. */
    public static function match(?string $text): ?string
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }

        foreach (self::ALL as $key => [$name, $aliases]) {
            if ($text === $key || in_array($text, $aliases, true) || $text === mb_strtolower($name)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * An old free-text payee read back into [bank key|null, holder|null]:
     * "FEDERAL - Annamalai" -> [federal, Annamalai], "Keerthi FI" -> [fi,
     * Keerthi], "CANA" -> [canara, null], "Thatha" -> [null, Thatha] (a
     * person, paid directly).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function parse(?string $payee): array
    {
        $payee = trim(preg_replace('/\s+/', ' ', (string) $payee));
        if ($payee === '') {
            return [null, null];
        }

        if ($bank = self::match($payee)) {
            return [$bank, null];
        }

        // "BANK - Holder" or "Holder - BANK"
        if (preg_match('/^(.+?)\s*[-–—:|\/]\s*(.+)$/u', $payee, $m)) {
            if ($bank = self::match($m[1])) {
                return [$bank, trim($m[2])];
            }
            if ($bank = self::match($m[2])) {
                return [$bank, trim($m[1])];
            }
        }

        // "Keerthi FI" / "FI Keerthi": a bank word at either end.
        $words = explode(' ', $payee);
        for ($n = min(4, count($words) - 1); $n >= 1; $n--) {
            if ($bank = self::match(implode(' ', array_slice($words, -$n)))) {
                return [$bank, implode(' ', array_slice($words, 0, -$n))];
            }
            if ($bank = self::match(implode(' ', array_slice($words, 0, $n)))) {
                return [$bank, implode(' ', array_slice($words, $n))];
            }
        }

        return [null, $payee];
    }
}
