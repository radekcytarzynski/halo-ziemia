<?php

declare(strict_types=1);

namespace App\Metrics;

use Doctrine\DBAL\Connection;

/**
 * Pary urządzenie–metryka obecne w tabeli próbek. GROUP BY po początku klucza głównego MariaDB wykonuje
 * jako "loose index scan" (skok po kluczu), więc nie czyta całej tabeli.
 */
final class SamplePairs
{
    /**
     * @param 'sample'|'sample_hourly' $table
     *
     * @return list<array{int, int}>
     */
    public static function of(Connection $connection, string $table): array
    {
        $pairs = [];
        foreach ($connection->fetchAllNumeric(\sprintf('SELECT device_id, metric_id FROM %s GROUP BY device_id, metric_id', $table)) as $row) {
            if (is_numeric($row[0] ?? null) && is_numeric($row[1] ?? null)) {
                $pairs[] = [(int) $row[0], (int) $row[1]];
            }
        }

        return $pairs;
    }
}
