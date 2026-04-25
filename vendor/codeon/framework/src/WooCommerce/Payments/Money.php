<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * Currency + decimal-precision helpers shared by every CodeOn
 * payment plugin.
 *
 * Internal comparisons run in integer minor units (e.g. GEL tetri,
 * USD/EUR cents). Only the outer API boundary converts back to the
 * major-unit decimal that TBC / BOG / Flitt JSON bodies expect.
 *
 * Pure helper, zero state. Lifted verbatim from the legacy
 * codeon-payments mega-plugin's `Codeon\Payments\Support\Money` so
 * the new micro-plugins can drop their copies and `use
 * CodeOn\Framework\WooCommerce\Payments\Money` instead.
 */
final class Money
{
    /** @var array<string,int> */
    private const MINOR_UNITS = [
        'GEL' => 2,
        'USD' => 2,
        'EUR' => 2,
    ];

    public const CURRENCY_GEL = 'GEL';

    public static function isSupported(string $currency): bool
    {
        return array_key_exists(strtoupper($currency), self::MINOR_UNITS);
    }

    public static function isGel(string $currency): bool
    {
        return self::CURRENCY_GEL === strtoupper($currency);
    }

    public static function toMinor(float $amount, string $currency): int
    {
        $exp = self::MINOR_UNITS[strtoupper($currency)] ?? 2;
        return (int) round($amount * (10 ** $exp));
    }

    public static function fromMinor(int $minor, string $currency): float
    {
        $exp = self::MINOR_UNITS[strtoupper($currency)] ?? 2;
        return $minor / (10 ** $exp);
    }

    /**
     * Format an amount for the bank's JSON body (rounded to that
     * currency's minor-unit precision).
     */
    public static function toApiDecimal(float $amount, string $currency): float
    {
        $exp = self::MINOR_UNITS[strtoupper($currency)] ?? 2;
        return round($amount, $exp);
    }

    public static function equals(float $a, float $b, string $currency): bool
    {
        return self::toMinor($a, $currency) === self::toMinor($b, $currency);
    }
}
