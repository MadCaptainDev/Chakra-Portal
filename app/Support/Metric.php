<?php

namespace App\Support;

/**
 * Number formatting for the public case-study screen.
 *
 * Audience numbers are read the way the platforms report them (3.2M, 42K) and
 * money the way an Indian client reads it (Rs 12.2L, Rs 1.4Cr), so the two get
 * separate formatters rather than one compromise.
 */
class Metric
{
    /** Rupee sign, kept in one place so the views never carry a raw glyph. */
    public const RUPEE = "\u{20B9}";

    /**
     * 3,200,000 -> "3.2M". Whole numbers lose the trailing ".0".
     */
    public static function count(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $number = (float) $value;

        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $unit => $suffix) {
            if (abs($number) >= $unit) {
                return self::trim($number / $unit).$suffix;
            }
        }

        return number_format($number);
    }

    /**
     * 1,220,000 -> "Rs 12.2L". Crore takes over above a hundred lakh.
     */
    public static function money(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $number = (float) $value;

        foreach ([10_000_000 => 'Cr', 100_000 => 'L'] as $unit => $suffix) {
            if (abs($number) >= $unit) {
                return self::RUPEE.self::trim($number / $unit).$suffix;
            }
        }

        return self::RUPEE.number_format($number);
    }

    /**
     * 8.4 -> "8.4%". Already a percentage, not a fraction.
     */
    public static function percent(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? '—' : self::trim((float) $value).'%';
    }

    /**
     * 32 -> "0:32", 95 -> "1:35".
     */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * How many times over the benchmark this piece did, e.g. "17.8x".
     * Null when there is nothing to compare against.
     */
    public static function multiple(int|float|null $actual, int|float|null $benchmark): ?string
    {
        if (! $actual || ! $benchmark) {
            return null;
        }

        return self::trim($actual / $benchmark)."\u{00D7}";
    }

    /**
     * One decimal place, but only when it says something.
     */
    private static function trim(float $number): string
    {
        $rounded = round($number, 1);

        return rtrim(rtrim(number_format($rounded, 1), '0'), '.');
    }
}
