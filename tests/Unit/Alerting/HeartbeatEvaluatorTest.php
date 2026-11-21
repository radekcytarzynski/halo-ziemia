<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\HeartbeatEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeartbeatEvaluatorTest extends TestCase
{
    #[DataProvider('provider')]
    public function testIsDown(string $lastSeen, int $interval, int $missed, int $grace, string $now, bool $expected): void
    {
        $down = (new HeartbeatEvaluator())->isDown(new \DateTimeImmutable($lastSeen), $interval, $missed, $grace, new \DateTimeImmutable($now));

        self::assertSame($expected, $down);
    }

    /**
     * @return iterable<string, array{string, int, int, int, string, bool}>
     */
    public static function provider(): iterable
    {
        // 60 s × 3 + 30 s = 210 s
        yield 'świeży kontakt' => ['2026-01-01 12:00:00', 60, 3, 30, '2026-01-01 12:01:00', false];
        yield 'dokładnie na granicy' => ['2026-01-01 12:00:00', 60, 3, 30, '2026-01-01 12:03:30', false];
        yield 'sekundę po granicy' => ['2026-01-01 12:00:00', 60, 3, 30, '2026-01-01 12:03:31', true];
        yield 'bez grace' => ['2026-01-01 12:00:00', 60, 3, 0, '2026-01-01 12:03:01', true];
        yield 'rzadki interwał' => ['2026-01-01 12:00:00', 3600, 2, 300, '2026-01-01 14:04:00', false];
        yield 'zegar cofnięty' => ['2026-01-01 12:00:00', 60, 3, 30, '2026-01-01 11:00:00', false];
    }
}
