<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Device;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceTest extends TestCase
{
    /**
     * @param list<string> $expectedAll
     */
    #[DataProvider('localIpProvider')]
    public function testLocalIps(mixed $snapshotIp, array $expectedAll, ?string $expectedPrimary): void
    {
        $device = self::device();
        $device->setLastSnapshot(['ip' => $snapshotIp]);

        self::assertSame($expectedAll, $device->getLocalIps());
        self::assertSame($expectedPrimary, $device->getPrimaryLocalIp());
    }

    /**
     * @return iterable<string, array{mixed, list<string>, ?string}>
     */
    public static function localIpProvider(): iterable
    {
        yield 'brak' => [null, [], null];
        yield 'lista' => [['192.168.1.10', '10.8.0.2'], ['192.168.1.10', '10.8.0.2'], '192.168.1.10'];
        yield 'IPv4 przed IPv6, nawet gdy później' => [['fd00::1', '192.168.1.10'], ['fd00::1', '192.168.1.10'], '192.168.1.10'];
        yield 'tylko IPv6' => [['fd00::1'], ['fd00::1'], 'fd00::1'];
        yield 'napis (starszy format)' => ['192.168.1.10 fd00::1', ['192.168.1.10', 'fd00::1'], '192.168.1.10'];
        yield 'śmieci pominięte' => [['', 5, '10.0.0.1'], ['10.0.0.1'], '10.0.0.1'];
    }

    public function testLocalIpsWithoutSnapshot(): void
    {
        self::assertSame([], self::device()->getLocalIps());
        self::assertNull(self::device()->getPrimaryLocalIp());
    }

    public function testDiskLabels(): void
    {
        $device = self::device();
        self::assertSame('/mnt/backup', $device->describeDisk('/mnt/backup'));

        $device->setDiskLabels(['sda' => 'Dysk danych', '/mnt/backup' => 'Backup']);

        self::assertSame(['/mnt/backup' => 'Backup', 'sda' => 'Dysk danych'], $device->getDiskLabels(), 'posortowane');
        self::assertSame('Backup', $device->getDiskLabel('/mnt/backup'));
        self::assertSame('Backup, /mnt/backup', $device->describeDisk('/mnt/backup'));
        self::assertSame('/', $device->describeDisk('/'));

        $device->setDiskLabels([]);
        self::assertSame([], $device->getDiskLabels());
    }

    private static function device(): Device
    {
        return new Device('NAS', 'nas', new \DateTimeImmutable('2026-11-20 12:00:00'));
    }
}
