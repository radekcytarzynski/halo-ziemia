<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metrics;

use App\Metrics\ChartQuery;
use App\Metrics\ChartRange;
use App\Metrics\Sparkline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SparklineTest extends TestCase
{
    public function testPathScalesToViewBoxAndBreaksOnGaps(): void
    {
        $path = Sparkline::path([[0, 1.0, 1.0, 1.0], [50, 3.0, 3.0, 3.0], [60, null, null, null], [100, 2.0, 2.0, 2.0]], 0, 100);

        self::assertSame('M0.0 30.0 L60.0 2.0 M120.0 16.0', $path);
    }

    public function testTooFewPoints(): void
    {
        self::assertNull(Sparkline::path([[0, 1.0, 1.0, 1.0]], 0, 100));
    }

    public function testFlatLine(): void
    {
        self::assertSame('M0.0 16.0 L120.0 16.0', Sparkline::path([[0, 5.0, 5.0, 5.0], [100, 5.0, 5.0, 5.0]], 0, 100));
    }

    /**
     * @param list<array{int, float, float, float}>                $points
     * @param list<array{int, float|null, float|null, float|null}> $expected
     */
    #[DataProvider('gapsProvider')]
    public function testWithGaps(array $points, int $bucket, array $expected): void
    {
        self::assertSame($expected, ChartQuery::withGaps($points, $bucket));
    }

    /**
     * @return iterable<string, array{list<array{int, float, float, float}>, int, list<array{int, float|null, float|null, float|null}>}>
     */
    public static function gapsProvider(): iterable
    {
        yield 'ciągłe' => [[[0, 1.0, 1.0, 1.0], [60, 1.0, 1.0, 1.0]], 60, [[0, 1.0, 1.0, 1.0], [60, 1.0, 1.0, 1.0]]];
        yield 'jeden brakujący kubełek tolerowany' => [[[0, 1.0, 1.0, 1.0], [120, 1.0, 1.0, 1.0]], 60, [[0, 1.0, 1.0, 1.0], [120, 1.0, 1.0, 1.0]]];
        yield 'przerwa' => [[[0, 1.0, 1.0, 1.0], [600, 2.0, 2.0, 2.0]], 60, [[0, 1.0, 1.0, 1.0], [60, null, null, null], [600, 2.0, 2.0, 2.0]]];
    }

    #[DataProvider('rangeProvider')]
    public function testRangeResolution(ChartRange $range, int $maxPoints): void
    {
        self::assertLessThanOrEqual($maxPoints, intdiv($range->seconds(), $range->bucketSeconds()));
    }

    /**
     * @return iterable<string, array{ChartRange, int}>
     */
    public static function rangeProvider(): iterable
    {
        foreach (ChartRange::cases() as $range) {
            yield $range->value => [$range, 1500];
        }
    }
}
