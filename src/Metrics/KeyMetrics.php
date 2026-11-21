<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Wybór kluczowych wskaźników ze snapshotu (do maili i kart urządzeń).
 */
final class KeyMetrics
{
    /** wzorzec klucza => etykieta (%s = fragment z wildcardu) */
    private const array PATTERNS = [
        'disk.*.used_pct' => 'Dysk %s',
        'cpu.temp_c' => 'Temperatura CPU',
        'smart.*.temp_c' => 'Temperatura %s',
        'load.1' => 'Load (1 min)',
        'mem.available_pct' => 'Wolna pamięć',
        'check.*.age_s' => 'Check %s – od ostatniego sukcesu',
        'uptime_s' => 'Uptime',
    ];

    /**
     * @param array<string, mixed>|null $snapshot
     *
     * @return list<array{key: string, label: string, value: float, unit: string|null}>
     */
    public static function fromSnapshot(?array $snapshot): array
    {
        $metrics = $snapshot['metrics'] ?? null;
        if (!\is_array($metrics)) {
            return [];
        }

        $result = [];
        foreach (self::PATTERNS as $pattern => $label) {
            foreach ($metrics as $key => $value) {
                if (!\is_string($key) || (!\is_int($value) && !\is_float($value))) {
                    continue;
                }
                $captured = self::match($pattern, $key);
                if (null === $captured) {
                    continue;
                }
                $result[] = [
                    'key' => $key,
                    'label' => \sprintf($label, $captured),
                    'value' => (float) $value,
                    'unit' => MetricUnit::guess($key),
                ];
            }
        }

        return $result;
    }

    private static function match(string $pattern, string $key): ?string
    {
        if (!str_contains($pattern, '*')) {
            return $pattern === $key ? '' : null;
        }
        [$prefix, $suffix] = explode('*', $pattern, 2);
        if (\strlen($key) <= \strlen($prefix) + \strlen($suffix) || !str_starts_with($key, $prefix) || !str_ends_with($key, $suffix)) {
            return null;
        }

        return substr($key, \strlen($prefix), -\strlen($suffix));
    }
}
