<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Settings\Settings;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Stan przechowywania danych: rozmiar tabel oraz to, czy rollup i retencja są uruchamiane przez cron.
 */
final readonly class StorageStatus
{
    /** Rollup co godzinę: ostrzeżenie, gdy zaległość przekracza tyle pełnych godzin. */
    public const int ROLLUP_STALE_HOURS = 3;
    /** Retencja raz na dobę: ostrzeżenie po tylu godzinach. */
    public const int RETENTION_STALE_HOURS = 48;

    public function __construct(
        private Connection $connection,
        private Settings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Tabele od największej. rows to przybliżenie InnoDB (table_rows), free = miejsce do odzyskania (OPTIMIZE).
     *
     * @return list<array{name: string, rows: int, bytes: int, free: int}>
     */
    public function tables(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT table_name AS name, COALESCE(table_rows, 0) AS rows_, COALESCE(data_length, 0) + COALESCE(index_length, 0) AS bytes, COALESCE(data_free, 0) AS free
             FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'
             ORDER BY bytes DESC',
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'name' => \is_string($row['name']) ? $row['name'] : '',
                'rows' => is_numeric($row['rows_']) ? (int) $row['rows_'] : 0,
                'bytes' => is_numeric($row['bytes']) ? (int) $row['bytes'] : 0,
                'free' => is_numeric($row['free']) ? (int) $row['free'] : 0,
            ];
        }

        return $result;
    }

    public function totalBytes(): int
    {
        return array_sum(array_column($this->tables(), 'bytes'));
    }

    public function rollupUntil(): ?\DateTimeImmutable
    {
        $until = $this->settings->rollupUntil();

        return null === $until ? null : new \DateTimeImmutable('@'.$until);
    }

    /**
     * Rollup zalega (albo nigdy nie działał, choć są próbki starsze niż kilka godzin).
     */
    public function rollupStale(): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $until = $this->settings->rollupUntil();
        if (null !== $until) {
            return HourlyRollup::hourStart($now) - $until > self::ROLLUP_STALE_HOURS * HourlyRollup::HOUR;
        }

        $oldest = $this->connection->fetchOne('SELECT ts FROM sample LIMIT 1');

        return is_numeric($oldest) && $now - (int) $oldest > self::ROLLUP_STALE_HOURS * HourlyRollup::HOUR;
    }

    public function lastRetentionAt(): ?\DateTimeImmutable
    {
        return $this->settings->lastRetentionAt();
    }

    /**
     * Retencja nie była uruchamiana od ponad doby (z zapasem). Jeśli nie działała nigdy – dopiero gdy są
     * już próbki starsze niż okres retencji (wcześniej i tak nie miałaby czego usuwać).
     */
    public function retentionStale(): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $last = $this->settings->lastRetentionAt();
        if (null !== $last) {
            return $now - $last->getTimestamp() > self::RETENTION_STALE_HOURS * 3600;
        }

        // Pierwszy wiersz klucza głównego: najstarsza próbka pierwszej pary – wystarczające przybliżenie, bez skanu.
        $oldest = $this->connection->fetchOne('SELECT ts FROM sample LIMIT 1');

        return is_numeric($oldest) && $now - (int) $oldest > ($this->settings->retentionRawDays() * 24 + self::RETENTION_STALE_HOURS) * 3600;
    }
}
