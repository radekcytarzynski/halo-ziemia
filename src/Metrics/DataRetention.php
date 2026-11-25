<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Enum\OutboxStatus;
use App\Settings\Settings;
use Doctrine\DBAL\Connection;

/**
 * Usuwanie danych starszych niż ustawienia retencji:
 *   - surowe próbki: starsze niż retentionRawDays, ale nigdy nie te, których rollup jeszcze nie objął,
 *   - rollupy godzinne, dziennik zdarzeń, zamknięte incydenty i wysłane/porzucone powiadomienia: starsze niż retentionRollupDays.
 *
 * Usuwanie porcjami (DELETE … LIMIT), żeby na hostingu współdzielonym nie trzymać długo blokad.
 */
final readonly class DataRetention
{
    private const int BATCH = 20000;

    public function __construct(
        private Connection $connection,
        private Settings $settings,
    ) {
    }

    public function run(\DateTimeImmutable $now, bool $dryRun = false): RetentionResult
    {
        $ts = $now->getTimestamp();
        $rawCutoff = $this->rawCutoff($ts);
        $longCutoff = $ts - $this->settings->retentionRollupDays() * 86400;
        $longCutoffDate = (new \DateTimeImmutable('@'.$longCutoff))->format('Y-m-d H:i:s');

        $samples = 0;
        if (null !== $rawCutoff) {
            foreach (SamplePairs::of($this->connection, 'sample') as [$d, $m]) {
                $samples += $this->purge('sample', 'device_id = ? AND metric_id = ? AND ts < ?', [$d, $m, $rawCutoff], $dryRun);
            }
        }

        $hourly = 0;
        foreach (SamplePairs::of($this->connection, 'sample_hourly') as [$d, $m]) {
            $hourly += $this->purge('sample_hourly', 'device_id = ? AND metric_id = ? AND hour_ts < ?', [$d, $m, $longCutoff], $dryRun);
        }

        $events = $this->purge('event', 'created_at < ?', [$longCutoffDate], $dryRun);
        $incidents = $this->purge('incident', 'closed_at IS NOT NULL AND closed_at < ?', [$longCutoffDate], $dryRun);
        $notifications = $this->purge(
            'notification_outbox',
            'status IN (?, ?) AND created_at < ?',
            [OutboxStatus::Sent->value, OutboxStatus::Failed->value, $longCutoffDate],
            $dryRun,
        );

        if (!$dryRun) {
            $this->settings->markRetention($now);
        }

        return new RetentionResult($samples, $hourly, $events, $incidents, $notifications, $rawCutoff, $longCutoff);
    }

    /**
     * Odzyskanie miejsca na dysku po usunięciu danych (InnoDB nie zmniejsza pliku tabeli sam z siebie).
     * Przebudowuje tabelę, więc uruchamiane tylko na żądanie.
     */
    public function optimize(): void
    {
        foreach (['sample', 'sample_hourly', 'event', 'notification_outbox'] as $table) {
            $this->connection->executeQuery('OPTIMIZE TABLE '.$table)->fetchAllAssociative();
        }
    }

    /**
     * Granica dla surowych próbek: pełna godzina, nie późniejsza niż początek ostatniej godziny objętej rollupem
     * (tę godzinę rollup przelicza ponownie, więc musi mieć komplet próbek). null = rollup jeszcze nie działał.
     */
    private function rawCutoff(int $now): ?int
    {
        $until = $this->settings->rollupUntil();
        if (null === $until) {
            return null;
        }

        return min(HourlyRollup::hourStart($now - $this->settings->retentionRawDays() * 86400), $until - HourlyRollup::HOUR);
    }

    /**
     * @param list<int|string> $params
     */
    private function purge(string $table, string $where, array $params, bool $dryRun): int
    {
        if ($dryRun) {
            $count = $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where), $params);

            return is_numeric($count) ? (int) $count : 0;
        }

        $total = 0;
        do {
            $deleted = (int) $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s LIMIT %d', $table, $where, self::BATCH), $params);
            $total += $deleted;
        } while ($deleted >= self::BATCH);

        return $total;
    }
}
