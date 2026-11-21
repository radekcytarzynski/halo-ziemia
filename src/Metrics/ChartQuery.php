<?php

declare(strict_types=1);

namespace App\Metrics;

use Doctrine\DBAL\Connection;

/**
 * Dane wykresów: kubełki min/avg/max.
 * Dane świeże z surowych próbek, starsze (sprzed najstarszej zachowanej próbki) z rollupów godzinnych.
 */
final readonly class ChartQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{int, float|null, float|null, float|null}> [ts, min, avg, max]; null = przerwa w danych
     */
    public function series(int $deviceId, int $metricId, ChartRange $range, \DateTimeImmutable $now): array
    {
        $to = $now->getTimestamp();
        $from = $to - $range->seconds();
        $bucket = $range->bucketSeconds();

        $oldestRaw = $this->connection->fetchOne(
            'SELECT MIN(ts) FROM sample WHERE device_id = ? AND metric_id = ? AND ts >= ?',
            [$deviceId, $metricId, $from],
        );
        $cutoff = is_numeric($oldestRaw) ? max($from, (int) $oldestRaw) : $to + 1;

        $points = [];
        if ($cutoff > $from) {
            $points = $this->rows(
                'SELECT FLOOR(hour_ts / :b) * :b AS t, MIN(min_value) AS mn, SUM(avg_value * sample_count) / SUM(sample_count) AS av, MAX(max_value) AS mx
                 FROM sample_hourly WHERE device_id = :d AND metric_id = :m AND hour_ts >= :from AND hour_ts < :to
                 GROUP BY t ORDER BY t',
                ['b' => max($bucket, 3600), 'd' => $deviceId, 'm' => $metricId, 'from' => $from, 'to' => $cutoff],
            );
        }

        $raw = $this->rows(
            'SELECT FLOOR(ts / :b) * :b AS t, MIN(value) AS mn, AVG(value) AS av, MAX(value) AS mx
             FROM sample WHERE device_id = :d AND metric_id = :m AND ts >= :from AND ts <= :to
             GROUP BY t ORDER BY t',
            ['b' => $bucket, 'd' => $deviceId, 'm' => $metricId, 'from' => max($from, $cutoff), 'to' => $to],
        );

        return self::withGaps(array_merge($points, $raw), $bucket);
    }

    /**
     * Wstawia punkt null, gdy przerwa między punktami jest dłuższa niż 2,5 kubełka (np. urządzenie DOWN),
     * żeby wykres nie łączył linią okresu bez danych.
     *
     * @param list<array{int, float, float, float}> $points
     *
     * @return list<array{int, float|null, float|null, float|null}>
     */
    public static function withGaps(array $points, int $bucket): array
    {
        $result = [];
        $previous = null;
        foreach ($points as $point) {
            if (null !== $previous && $point[0] - $previous > (int) ($bucket * 2.5)) {
                $result[] = [$previous + $bucket, null, null, null];
            }
            $result[] = $point;
            $previous = $point[0];
        }

        return $result;
    }

    /**
     * @param array<string, int> $params
     *
     * @return list<array{int, float, float, float}>
     */
    private function rows(string $sql, array $params): array
    {
        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            if (!is_numeric($row['t']) || !is_numeric($row['mn']) || !is_numeric($row['av']) || !is_numeric($row['mx'])) {
                continue;
            }
            $result[] = [(int) $row['t'], round((float) $row['mn'], 4), round((float) $row['av'], 4), round((float) $row['mx'], 4)];
        }

        return $result;
    }
}
