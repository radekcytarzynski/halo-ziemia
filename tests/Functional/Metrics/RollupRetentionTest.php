<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metrics;

use App\Metrics\DataRetention;
use App\Metrics\HourlyRollup;
use App\Metrics\StorageStatus;
use App\Settings\Settings;
use App\Tests\Functional\DeviceFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RollupRetentionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DeviceFixtures;

    private const string NOW = '2026-11-20 12:00:20';
    private const int DAY = 86400;

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        self::bootKernel();
    }

    public function testRollupAggregatesFullHoursAndRecomputesLastHour(): void
    {
        self::assertSame(0, $this->rollup()->run(new \DateTimeImmutable(self::NOW))->hours, 'pusta tabela');

        $this->samples(1, 10, ['09:00:00' => 1, '09:30:00' => 3, '10:15:00' => 5, '11:59:59' => 7, '12:00:00' => 9]);
        $this->samples(2, 10, ['10:00:00' => 2]);

        $result = $this->rollup()->run(new \DateTimeImmutable(self::NOW));

        self::assertSame(3, $result->hours);
        self::assertSame(self::ts('12:00:00'), $result->until);
        self::assertSame(self::ts('12:00:00'), $this->settings()->rollupUntil());
        self::assertSame([
            [1, 10, self::ts('09:00:00'), 1.0, 2.0, 3.0, 2],
            [1, 10, self::ts('10:00:00'), 5.0, 5.0, 5.0, 1],
            [1, 10, self::ts('11:00:00'), 7.0, 7.0, 7.0, 1],
            [2, 10, self::ts('10:00:00'), 2.0, 2.0, 2.0, 1],
        ], $this->hourly(), 'bieżąca godzina (12:00) pominięta');

        // Próbka zapisana w 11. godzinie już po rollupie + nowa godzina
        $this->samples(1, 10, ['11:30:00' => 1, '12:30:00' => 4]);
        $result = $this->rollup()->run(new \DateTimeImmutable('2026-11-20 13:05:00'));

        self::assertSame(2, $result->hours, '11:00 (ponownie) i 12:00');
        $hourly = $this->hourly();
        self::assertContains([1, 10, self::ts('11:00:00'), 1.0, 4.0, 7.0, 2], $hourly);
        self::assertContains([1, 10, self::ts('12:00:00'), 4.0, 6.5, 9.0, 2], $hourly);

        $this->rollup()->run(new \DateTimeImmutable('2026-11-20 13:05:00'));
        self::assertSame($hourly, $this->hourly(), 'ponowne uruchomienie niczego nie zmienia');
    }

    public function testRetentionKeepsRawSamplesUntilRolledUp(): void
    {
        $now = self::ts('12:00:20');
        $this->sampleAt(1, 10, $now - 20 * self::DAY);
        $this->sampleAt(1, 10, $now - 15 * self::DAY);
        $this->sampleAt(1, 10, $now - 1 * self::DAY);

        $result = $this->retention()->run(new \DateTimeImmutable(self::NOW));
        self::assertNull($result->rawCutoff, 'rollup jeszcze nie działał – próbek nie ruszamy');
        self::assertSame(3, $this->rows('sample'));

        // Rollup zatrzymał się 16 dni temu: usuwamy tylko to, co już jest w rollupach
        $this->settings()->markRollupUntil(HourlyRollup::hourStart($now - 16 * self::DAY));
        $result = $this->retention()->run(new \DateTimeImmutable(self::NOW));
        self::assertSame(1, $result->samples);
        self::assertSame(2, $this->rows('sample'), 'próbka sprzed 15 dni zostaje, bo nie ma jej w rollupach');

        // Rollup aktualny: granica = 14 dni (pełna godzina)
        $this->settings()->markRollupUntil(HourlyRollup::hourStart($now));
        $result = $this->retention()->run(new \DateTimeImmutable(self::NOW));
        self::assertSame(HourlyRollup::hourStart($now - 14 * self::DAY), $result->rawCutoff);
        self::assertSame(1, $result->samples);
        self::assertSame(1, $this->rows('sample'));
    }

    public function testRetentionRemovesOldRollupsEventsIncidentsAndFinishedNotifications(): void
    {
        [$device] = $this->createDeviceWithToken();
        $deviceId = (int) $device->getId();
        $now = self::ts('12:00:20');
        $old = gmdate('Y-m-d H:i:s', $now - 401 * self::DAY);
        $recent = gmdate('Y-m-d H:i:s', $now - 10 * self::DAY);
        $db = $this->db();
        $this->settings()->markRollupUntil(HourlyRollup::hourStart($now));

        foreach ([$now - 401 * self::DAY, $now - 10 * self::DAY] as $hour) {
            $db->insert('sample_hourly', ['device_id' => $deviceId, 'metric_id' => 10, 'hour_ts' => HourlyRollup::hourStart($hour), 'min_value' => 1, 'avg_value' => 1, 'max_value' => 1, 'sample_count' => 60]);
        }
        $db->executeStatement('DELETE FROM event');
        foreach ([$old, $recent] as $at) {
            $db->insert('event', ['type' => 'config_change', 'message' => 'x', 'created_at' => $at, 'device_id' => $deviceId]);
        }
        foreach ([[$old, $old], [$old, null], [$recent, $recent]] as [$opened, $closed]) {
            $db->insert('incident', ['device_id' => $deviceId, 'subject' => 's', 'title' => 't', 'max_severity' => 'crit', 'opened_at' => $opened, 'closed_at' => $closed]);
        }
        foreach ([['sent', $old], ['failed', $old], ['pending', $old], ['sent', $recent]] as [$status, $at]) {
            $db->insert('notification_outbox', ['channel' => 'email', 'address' => 'a@example.test', 'subject' => 's', 'body_text' => 'b', 'status' => $status, 'attempts' => 1, 'next_attempt_at' => $at, 'created_at' => $at]);
        }

        $dry = $this->retention()->run(new \DateTimeImmutable(self::NOW), dryRun: true);
        self::assertSame([1, 1, 1, 2], [$dry->hourly, $dry->events, $dry->incidents, $dry->notifications]);
        self::assertSame(2, $this->rows('sample_hourly'), '--dry-run niczego nie usuwa');
        self::assertNull($this->settings()->lastRetentionAt());

        $result = $this->retention()->run(new \DateTimeImmutable(self::NOW));

        self::assertSame([1, 1, 1, 2], [$result->hourly, $result->events, $result->incidents, $result->notifications]);
        self::assertSame(1, $this->rows('sample_hourly'));
        self::assertSame(1, $this->rows('event'));
        self::assertSame(2, $this->rows('incident'), 'otwarty incydent zostaje mimo wieku');
        self::assertSame(['pending', 'sent'], $db->fetchFirstColumn('SELECT status FROM notification_outbox ORDER BY status'));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->settings()->lastRetentionAt());
    }

    public function testStorageStatusDetectsMissingCron(): void
    {
        $status = self::getContainer()->get(StorageStatus::class);
        $now = self::ts('12:00:20');
        self::assertFalse($status->rollupStale(), 'brak danych');
        self::assertFalse($status->retentionStale());

        $this->sampleAt(1, 10, $now - 5 * 3600);
        self::assertTrue($status->rollupStale(), 'próbki sprzed 5 h, rollup nigdy nie działał');
        self::assertFalse($status->retentionStale(), 'dane młodsze niż okres retencji');

        $this->settings()->markRollupUntil(HourlyRollup::hourStart($now) - 3600);
        self::assertFalse($status->rollupStale());
        $this->settings()->markRollupUntil(HourlyRollup::hourStart($now) - 4 * 3600);
        self::assertTrue($status->rollupStale());

        $this->sampleAt(1, 9, $now - 17 * self::DAY);
        self::assertTrue($status->retentionStale(), 'próbki starsze niż retencja + 2 dni, a retencja nigdy nie działała');
        $this->settings()->markRetention(new \DateTimeImmutable('2026-11-19 03:30:00'));
        self::assertFalse($status->retentionStale());
        $this->settings()->markRetention(new \DateTimeImmutable('2026-11-18 03:30:00'));
        self::assertTrue($status->retentionStale());

        self::assertContains('sample', array_column($status->tables(), 'name'));
    }

    public function testCommands(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $this->samples(1, 10, ['10:15:00' => 5]);

        $rollup = new CommandTester($application->find('app:rollup'));
        self::assertSame(Command::SUCCESS, $rollup->execute([]));
        self::assertStringContainsString('rollup do 2026-11-20 12:00 UTC', $rollup->getDisplay());

        $retention = new CommandTester($application->find('app:retention'));
        self::assertSame(Command::SUCCESS, $retention->execute(['--dry-run' => true]));
        self::assertStringContainsString('Do usunięcia (--dry-run): próbki 0', $retention->getDisplay());
        self::assertStringContainsString('próbki surowe sprzed 2026-11-06 12:00 UTC (14 dni)', $retention->getDisplay());
        self::assertNull($this->settings()->lastRetentionAt());

        self::assertSame(Command::SUCCESS, $retention->execute([]));
        self::assertStringContainsString('Usunięto: próbki 0', $retention->getDisplay());

        $status = new CommandTester($application->find('app:status'));
        $status->execute([]);
        self::assertStringContainsString('rollup obejmuje dane do 2026-11-20 12:00 UTC; retencja przed chwilą', $status->getDisplay());
    }

    private static function ts(string $time): int
    {
        return (new \DateTimeImmutable('2026-11-20 '.$time.' UTC'))->getTimestamp();
    }

    /**
     * @param array<string, int> $values godzina => wartość
     */
    private function samples(int $deviceId, int $metricId, array $values): void
    {
        foreach ($values as $time => $value) {
            $this->db()->insert('sample', ['device_id' => $deviceId, 'metric_id' => $metricId, 'ts' => self::ts($time), 'value' => $value]);
        }
    }

    private function sampleAt(int $deviceId, int $metricId, int $ts): void
    {
        $this->db()->insert('sample', ['device_id' => $deviceId, 'metric_id' => $metricId, 'ts' => $ts, 'value' => 1]);
    }

    /**
     * @return list<array{int, int, int, float, float, float, int}>
     */
    private function hourly(): array
    {
        $rows = [];
        foreach ($this->db()->fetchAllNumeric('SELECT device_id, metric_id, hour_ts, min_value, avg_value, max_value, sample_count FROM sample_hourly ORDER BY device_id, metric_id, hour_ts') as $r) {
            $n = array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : \NAN, $r);
            $rows[] = [(int) $n[0], (int) $n[1], (int) $n[2], $n[3], $n[4], $n[5], (int) $n[6]];
        }

        return $rows;
    }

    private function rows(string $table): int
    {
        $count = $this->db()->fetchOne('SELECT COUNT(*) FROM '.$table);

        return is_numeric($count) ? (int) $count : -1;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function settings(): Settings
    {
        return self::getContainer()->get(Settings::class);
    }

    private function rollup(): HourlyRollup
    {
        return self::getContainer()->get(HourlyRollup::class);
    }

    private function retention(): DataRetention
    {
        return self::getContainer()->get(DataRetention::class);
    }
}
