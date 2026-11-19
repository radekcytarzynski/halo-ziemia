<?php

declare(strict_types=1);

namespace App\Metrics;

use Doctrine\DBAL\Connection;

/**
 * Zapis surowych próbek wsadowo (multi-row INSERT). Ponowny heartbeat w tej samej sekundzie nadpisuje wartość.
 */
final readonly class SampleWriter
{
    private const int CHUNK = 500;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<int, float> $valuesByMetricId
     */
    public function write(int $deviceId, int $ts, array $valuesByMetricId): void
    {
        foreach (array_chunk($valuesByMetricId, self::CHUNK, true) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $metricId => $value) {
                $placeholders[] = '(?, ?, ?, ?)';
                array_push($params, $deviceId, $metricId, $ts, $value);
            }

            $this->connection->executeStatement(
                'INSERT INTO sample (device_id, metric_id, ts, value) VALUES '.implode(', ', $placeholders)
                .' ON DUPLICATE KEY UPDATE value = VALUES(value)',
                $params,
            );
        }
    }
}
