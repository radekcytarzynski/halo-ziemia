<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Settings\Settings;
use Doctrine\DBAL\Connection;

/**
 * Agregacja surowych próbek do rollupów godzinnych (min/avg/max/liczba).
 *
 * Przetwarza pełne godziny od znacznika Settings::rollupUntil() do początku bieżącej godziny, a ostatnią
 * zagregowaną godzinę liczy jeszcze raz (próbka zapisana tuż po pełnej godzinie). Wynik jest nadpisywany
 * (ON DUPLICATE KEY UPDATE), więc ponowne uruchomienie jest bezpieczne. Zapytania idą osobno dla każdej
 * pary urządzenie–metryka, bo tylko tak korzystają z klucza głównego (device_id, metric_id, ts).
 */
final readonly class HourlyRollup
{
    public const int HOUR = 3600;

    public function __construct(
        private Connection $connection,
        private Settings $settings,
    ) {
    }

    public function run(\DateTimeImmutable $now): RollupResult
    {
        $end = self::hourStart($now->getTimestamp());
        $from = $this->startFrom();
        if (null === $from || $from >= $end) {
            return new RollupResult(0, 0, $this->settings->rollupUntil());
        }

        $rows = 0;
        foreach (SamplePairs::of($this->connection, 'sample') as [$deviceId, $metricId]) {
            $rows += (int) $this->connection->executeStatement(
                'INSERT INTO sample_hourly (device_id, metric_id, hour_ts, min_value, avg_value, max_value, sample_count)
                 SELECT device_id, metric_id, ts - ts % 3600 AS h, MIN(value), AVG(value), MAX(value), COUNT(*)
                 FROM sample WHERE device_id = :d AND metric_id = :m AND ts >= :from AND ts < :to
                 GROUP BY device_id, metric_id, h
                 ON DUPLICATE KEY UPDATE min_value = VALUES(min_value), avg_value = VALUES(avg_value),
                     max_value = VALUES(max_value), sample_count = VALUES(sample_count)',
                ['d' => $deviceId, 'm' => $metricId, 'from' => $from, 'to' => $end],
            );
        }
        $this->settings->markRollupUntil($end);

        return new RollupResult(intdiv($end - $from, self::HOUR), $rows, $end);
    }

    public static function hourStart(int $ts): int
    {
        return $ts - $ts % self::HOUR;
    }

    /**
     * Pierwsze uruchomienie: od najstarszej próbki. Kolejne: od ostatniej zagregowanej godziny.
     */
    private function startFrom(): ?int
    {
        $until = $this->settings->rollupUntil();
        if (null !== $until) {
            return $until - self::HOUR;
        }

        $oldest = $this->connection->fetchOne('SELECT MIN(ts) FROM sample');

        return is_numeric($oldest) ? self::hourStart((int) $oldest) : null;
    }
}
