<?php

namespace App\Support;

/**
 * Reading a user agent well enough to say "Chrome on Windows".
 *
 * Deliberately a handful of ordered patterns rather than a parsing library.
 * The only question this answers is "would the owner of this account recognise
 * this device as theirs?", and for that a wrong guess between two Chromium
 * forks costs nothing while a new dependency costs something forever.
 *
 * Order matters throughout. Edge and Opera both claim to be Chrome, Chrome
 * claims to be Safari, and every browser on iOS is Safari underneath -- so the
 * more specific token has to be tested first, or everything reads as Safari.
 */
class Device
{
    public const DESKTOP = 'desktop';

    public const PHONE = 'phone';

    public const TABLET = 'tablet';

    /** Ordered: the impostors before the browser they impersonate. */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'CriOS' => 'Chrome',
        'FxiOS' => 'Firefox',
        'Firefox' => 'Firefox',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    /** Ordered: iPad and iPhone before Mac, Android before Linux. */
    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Macintosh' => 'Mac',
        'Mac OS X' => 'Mac',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    /**
     * @return array{browser: ?string, platform: ?string, kind: string, label: string}
     */
    public static function describe(?string $userAgent): array
    {
        $agent = trim((string) $userAgent);

        $browser = self::firstMatch($agent, self::BROWSERS);
        $platform = self::firstMatch($agent, self::PLATFORMS);
        $kind = self::kind($agent, $platform);

        return [
            'browser' => $browser,
            'platform' => $platform,
            'kind' => $kind,
            'label' => self::label($browser, $platform),
        ];
    }

    /**
     * "Chrome on Windows", or as much of it as the agent string admits to.
     *
     * An unrecognised agent says so rather than guessing. "Unknown device" is
     * information -- it is usually a script or an old phone, and either is
     * worth a second look before it is dismissed.
     */
    private static function label(?string $browser, ?string $platform): string
    {
        return match (true) {
            $browser && $platform => $browser.' on '.$platform,
            (bool) $browser => $browser,
            (bool) $platform => $platform,
            default => 'Unknown device',
        };
    }

    private static function kind(string $agent, ?string $platform): string
    {
        if ($platform === 'iPad' || str_contains($agent, 'Tablet')) {
            return self::TABLET;
        }

        // "Mobile" is the token Android phones carry and Android tablets omit,
        // which is the only reliable way the two are told apart.
        if (in_array($platform, ['iPhone', 'Android'], true) || str_contains($agent, 'Mobile')) {
            return self::PHONE;
        }

        return self::DESKTOP;
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function firstMatch(string $agent, array $map): ?string
    {
        foreach ($map as $token => $name) {
            if (str_contains($agent, $token)) {
                return $name;
            }
        }

        return null;
    }
}
