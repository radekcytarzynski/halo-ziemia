<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\Formatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    private const string NBSP = "\u{00A0}";

    #[DataProvider('metricProvider')]
    public function testMetric(float|int $value, ?string $unit, string $expected): void
    {
        self::assertSame($expected, str_replace(self::NBSP, ' ', Formatter::metric($value, $unit)));
    }

    /**
     * @return iterable<string, array{float|int, ?string, string}>
     */
    public static function metricProvider(): iterable
    {
        yield 'procent' => [96.14, '%', '96,1 %'];
        yield 'procent całkowity' => [40.0, '%', '40 %'];
        yield 'temperatura' => [48.5, '°C', '48,5 °C'];
        yield 'bajty GB' => [300000000000, 'B', '300 GB'];
        yield 'bajty TB' => [1500000000000, 'B', '1,5 TB'];
        yield 'kB' => [5000000, 'kB', '5 GB'];
        yield 'sekundy' => [5400, 's', '1 h 30 min'];
        yield 'bez jednostki' => [0.125, null, '0,13'];
        yield 'tysiące' => [12000, 'h', '12 000 h'];
    }

    #[DataProvider('durationProvider')]
    public function testDuration(int $seconds, string $expected): void
    {
        self::assertSame($expected, Formatter::duration($seconds));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function durationProvider(): iterable
    {
        yield 'sekundy' => [45, '45 s'];
        yield 'równe minuty' => [120, '2 min'];
        yield 'minuty i sekundy' => [125, '2 min 5 s'];
        yield 'godziny' => [3600, '1 h'];
        yield 'dni i godziny' => [190800, '2 d 5 h'];
        yield 'dni bez godzin' => [86400 + 120, '1 d 2 min'];
        yield 'ujemne' => [-5, '0 s'];
    }

    public function testAgo(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 12:00:00');

        self::assertSame('przed chwilą', Formatter::ago(new \DateTimeImmutable('2026-01-01 11:59:55'), $now));
        self::assertSame('3 min temu', Formatter::ago(new \DateTimeImmutable('2026-01-01 11:57:00'), $now));
        self::assertSame('przed chwilą', Formatter::ago(new \DateTimeImmutable('2026-01-01 12:05:00'), $now));
    }
}
