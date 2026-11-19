<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\CheckStatus;
use App\Enum\DeviceStatus;
use App\Enum\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    /**
     * @param list<Severity> $severities
     */
    #[DataProvider('worstProvider')]
    public function testWorstPicksHighestRank(array $severities, Severity $expected): void
    {
        self::assertSame($expected, Severity::worst(...$severities));
    }

    /**
     * @return iterable<string, array{list<Severity>, Severity}>
     */
    public static function worstProvider(): iterable
    {
        yield 'brak składowych = OK' => [[], Severity::Ok];
        yield 'same OK' => [[Severity::Ok, Severity::Ok], Severity::Ok];
        yield 'WARN wygrywa z OK' => [[Severity::Ok, Severity::Warn], Severity::Warn];
        yield 'CRIT wygrywa z WARN' => [[Severity::Warn, Severity::Crit, Severity::Ok], Severity::Crit];
        yield 'DOWN najgorszy' => [[Severity::Crit, Severity::Down, Severity::Warn], Severity::Down];
    }

    #[DataProvider('comparisonProvider')]
    public function testComparisons(Severity $a, Severity $b, bool $worse, bool $atLeast): void
    {
        self::assertSame($worse, $a->isWorseThan($b));
        self::assertSame($atLeast, $a->isAtLeast($b));
    }

    /**
     * @return iterable<string, array{Severity, Severity, bool, bool}>
     */
    public static function comparisonProvider(): iterable
    {
        yield 'równe' => [Severity::Warn, Severity::Warn, false, true];
        yield 'gorszy' => [Severity::Crit, Severity::Warn, true, true];
        yield 'lepszy' => [Severity::Ok, Severity::Crit, false, false];
        yield 'DOWN ≥ CRIT' => [Severity::Down, Severity::Crit, true, true];
    }

    #[DataProvider('checkStatusProvider')]
    public function testCheckStatusMapsToSeverity(CheckStatus $status, Severity $expected): void
    {
        self::assertSame($expected, $status->toSeverity());
    }

    /**
     * @return iterable<string, array{CheckStatus, Severity}>
     */
    public static function checkStatusProvider(): iterable
    {
        yield 'ok' => [CheckStatus::Ok, Severity::Ok];
        yield 'warn' => [CheckStatus::Warn, Severity::Warn];
        yield 'fail' => [CheckStatus::Fail, Severity::Crit];
    }

    public function testDeviceStatusFromSeverityCoversAllCases(): void
    {
        foreach (Severity::cases() as $severity) {
            self::assertSame($severity->value, DeviceStatus::fromSeverity($severity)->value);
        }
    }
}
