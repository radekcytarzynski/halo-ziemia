<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Zakres wykresu i rozdzielczość (szerokość kubełka) dobrana do zakresu.
 */
enum ChartRange: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';
    case Year = '1y';

    public function seconds(): int
    {
        return match ($this) {
            self::Day => 86400,
            self::Week => 7 * 86400,
            self::Month => 30 * 86400,
            self::Year => 365 * 86400,
        };
    }

    /** ~500–1500 punktów na wykres. */
    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Day => 60,
            self::Week => 900,
            self::Month => 3600,
            self::Year => 21600,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Day => '24 h',
            self::Week => '7 dni',
            self::Month => '30 dni',
            self::Year => '1 rok',
        };
    }
}
