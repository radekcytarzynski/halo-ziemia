<?php

declare(strict_types=1);

namespace App\Tests\Unit\Device;

use App\Device\DiskInventory;
use App\Device\DiskRow;
use App\Entity\Device;
use App\Metrics\KeyMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiskInventoryTest extends TestCase
{
    public function testRowsFromSnapshotAndLabels(): void
    {
        $device = self::device();
        $device->setDiskLabels(['/mnt/backup' => 'Backup', '/mnt/old' => 'Stary dysk', 'sda' => 'System']);

        $rows = DiskInventory::rows($device);

        self::assertSame(
            [
                ['/', DiskRow::KIND_MOUNT, '/dev/sda1', 40.0, null, true],
                ['/mnt/backup', DiskRow::KIND_MOUNT, '/dev/sdb1', 91.0, 'Backup', true],
                ['sda', DiskRow::KIND_SMART, '/dev/sda', 35.0, 'System', true],
                ['md0', DiskRow::KIND_RAID, 'active', null, null, true],
                ['/mnt/old', DiskRow::KIND_GONE, null, null, 'Stary dysk', false],
            ],
            array_map(static fn (DiskRow $r): array => [$r->id, $r->kind, $r->detail, $r->value, $r->label, $r->reported], $rows),
        );
        self::assertSame(['/', '/mnt/backup'], DiskInventory::mounts($device));
    }

    public function testRowsWithoutSnapshot(): void
    {
        self::assertSame([], DiskInventory::rows(new Device('NAS', 'nas', new \DateTimeImmutable('2026-11-20'))));
    }

    #[DataProvider('labelProvider')]
    public function testNormalizeLabel(string $input, string $expected): void
    {
        self::assertSame($expected, DiskInventory::normalizeLabel($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'zwykła' => ['Backup', 'Backup'];
        yield 'spacje' => ["  Dysk   danych \t", 'Dysk danych'];
        yield 'znaki sterujące' => ["Ba\x00ck\nup", 'Ba ck up'];
        yield 'pusta' => ['   ', ''];
        yield 'za długa' => [str_repeat('ą', 60), str_repeat('ą', DiskInventory::MAX_LABEL_LENGTH)];
    }

    public function testKeyMetricsUseDiskLabels(): void
    {
        $device = self::device();
        $labels = KeyMetrics::fromSnapshot($device->getLastSnapshot(), ['/mnt/backup' => 'Backup', 'sda' => 'System', 'backup' => 'nie dla checków']);

        $byKey = array_column($labels, 'label', 'key');
        self::assertSame('Dysk Backup, /mnt/backup', $byKey['disk./mnt/backup.used_pct']);
        self::assertSame('Dysk /', $byKey['disk./.used_pct']);
        self::assertSame('Temperatura System, sda', $byKey['smart.sda.temp_c']);
        self::assertSame('Check backup – od ostatniego sukcesu', $byKey['check.backup.age_s']);
    }

    private static function device(): Device
    {
        $device = new Device('NAS', 'nas', new \DateTimeImmutable('2026-11-20 12:00:00'));
        $device->setLastSnapshot([
            'metrics' => ['disk./.used_pct' => 40.0, 'disk./mnt/backup.used_pct' => 91, 'smart.sda.temp_c' => 35.0, 'check.backup.age_s' => 60.0],
            'disks' => [['mount' => '/', 'device' => '/dev/sda1'], ['mount' => '/mnt/backup', 'device' => '/dev/sdb1']],
            'smart' => [['name' => 'sda', 'passed' => true]],
            'raid' => [['name' => 'md0', 'state' => 'active', 'degraded' => false]],
        ]);

        return $device;
    }
}
