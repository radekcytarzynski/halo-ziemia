<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metrics;

use App\Metrics\KeyMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyMetricsTest extends TestCase
{
    #[DataProvider('labelProvider')]
    public function testLabel(string $key, ?string $expected): void
    {
        self::assertSame($expected, KeyMetrics::label($key, ['/srv/backup' => 'Backup', 'sda' => 'Lexar', 'backup' => 'nie dla checków']));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'kluczowa' => ['cpu.temp_c', 'Temperatura CPU'];
        yield 'dysk z nazwą' => ['disk./srv/backup.used_pct', 'Dysk Backup, /srv/backup'];
        yield 'dysk bez nazwy' => ['disk./.used_pct', 'Dysk /'];
        yield 'pozostała z nazwą' => ['disk./srv/backup.free_b', 'Wolne miejsce Backup, /srv/backup'];
        yield 'SMART' => ['smart.sda.percentage_used', 'Zużycie SSD Lexar, sda'];
        yield 'check bez nazwy dysku' => ['check.backup.age_s', 'Check backup – od ostatniego sukcesu'];
        yield 'nieznana' => ['custom.queue_len', null];
    }
}
