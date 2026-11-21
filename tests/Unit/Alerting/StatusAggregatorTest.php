<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\StatusAggregator;
use App\Enum\DeviceStatus;
use App\Enum\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusAggregatorTest extends TestCase
{
    /**
     * @param list<Severity> $severities
     */
    #[DataProvider('provider')]
    public function testAggregate(bool $enabled, bool $everSeen, array $severities, DeviceStatus $expected): void
    {
        self::assertSame($expected, (new StatusAggregator())->aggregate($enabled, $everSeen, $severities));
    }

    /**
     * @return iterable<string, array{bool, bool, list<Severity>, DeviceStatus}>
     */
    public static function provider(): iterable
    {
        yield 'wyłączone' => [false, true, [Severity::Crit], DeviceStatus::Paused];
        yield 'bez kontaktu' => [true, false, [], DeviceStatus::Pending];
        yield 'wszystko OK' => [true, true, [Severity::Ok, Severity::Ok], DeviceStatus::Ok];
        yield 'brak stanów = OK' => [true, true, [], DeviceStatus::Ok];
        yield 'najgorszy wygrywa' => [true, true, [Severity::Warn, Severity::Crit, Severity::Ok], DeviceStatus::Crit];
        yield 'DOWN' => [true, true, [Severity::Down, Severity::Warn], DeviceStatus::Down];
    }
}
