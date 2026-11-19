<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Jednostka metryki wywnioskowana z sufiksu klucza.
 */
final class MetricUnit
{
    private const array SUFFIXES = [
        '_pct' => '%',
        '_c' => '°C',
        '_b' => 'B',
        '_kb' => 'kB',
        '_s' => 's',
        '_hours' => 'h',
    ];

    public static function guess(string $key): ?string
    {
        foreach (self::SUFFIXES as $suffix => $unit) {
            if (str_ends_with($key, $suffix)) {
                return $unit;
            }
        }

        return null;
    }
}
