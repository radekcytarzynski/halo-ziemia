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

    /** Etykiety pozostałych metryk (lista wyboru wykresu, miniwykres na pulpicie). */
    private const array OTHER_LABELS = [
        'load.5' => 'Load (5 min)',
        'load.15' => 'Load (15 min)',
        'mem.available_kb' => 'Wolna pamięć',
        'mem.swap_used_pct' => 'Swap',
        'mem.swap_used_kb' => 'Swap',
        'disk.*.free_b' => 'Wolne miejsce %s',
        'disk.*.inodes_used_pct' => 'I-węzły %s',
        'smart.*.percentage_used' => 'Zużycie SSD %s',
        'smart.*.power_on_hours' => 'Czas pracy %s',
        'smart.*.reallocated' => 'Realokowane sektory %s',
        'smart.*.pending' => 'Sektory oczekujące %s',
        'smart.*.passed' => 'SMART %s',
        'raid.*.degraded' => 'RAID %s zdegradowany',
        'rpi.throttled_now' => 'RPi: dławienie teraz',
        'rpi.throttled' => 'RPi: dławienie',
    ];

    /**
     * Czytelna etykieta metryki z nazwą dysku z panelu, np. "Dysk Backup, /srv/backup". null = metryka bez etykiety.
     *
     * @param array<string, string> $diskLabels identyfikator dysku => nazwa
     */
    public static function label(string $key, array $diskLabels = []): ?string
    {
        foreach ([self::PATTERNS, self::OTHER_LABELS] as $patterns) {
            foreach ($patterns as $pattern => $label) {
                $captured = self::match($pattern, $key);
                if (null !== $captured) {
                    $named = isset($diskLabels[$captured]) && !str_starts_with($pattern, 'check.') ? $diskLabels[$captured].', '.$captured : $captured;

                    return \sprintf($label, $named);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @param array<string, string>     $diskLabels nazwy dysków z panelu (identyfikator => nazwa)
     *
     * @return list<array{key: string, label: string, value: float, unit: string|null}>
     */
    public static function fromSnapshot(?array $snapshot, array $diskLabels = []): array
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
                if (null === self::match($pattern, $key)) {
                    continue;
                }
                $result[] = [
                    'key' => $key,
                    'label' => self::label($key, $diskLabels) ?? $key,
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
