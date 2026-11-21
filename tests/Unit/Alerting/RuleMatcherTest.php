<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\RuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleMatcherTest extends TestCase
{
    #[DataProvider('provider')]
    public function testMatch(string $target, string $key, ?string $expected): void
    {
        self::assertSame($expected, RuleMatcher::match($target, $key));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function provider(): iterable
    {
        yield 'dokładne' => ['cpu.temp_c', 'cpu.temp_c', ''];
        yield 'dokładne – brak' => ['cpu.temp_c', 'cpu.temp', null];
        yield 'root' => ['disk.*.used_pct', 'disk./.used_pct', '/'];
        yield 'ścieżka z kropkami' => ['disk.*.used_pct', 'disk./mnt/my.data.used_pct', '/mnt/my.data'];
        yield 'inny sufiks' => ['disk.*.used_pct', 'disk./.free_b', null];
        yield 'pusty fragment' => ['disk.*.used_pct', 'disk..used_pct', null];
        yield 'smart' => ['smart.*.temp_c', 'smart.nvme0.temp_c', 'nvme0'];
        yield 'wszystkie checki' => ['*', 'backup', 'backup'];
        yield 'dwa wildcardy nieobsługiwane' => ['*.*', 'a.b', null];
    }

    public function testMatchAll(): void
    {
        self::assertSame(
            ['disk./.used_pct' => '/', 'disk./mnt/backup.used_pct' => '/mnt/backup'],
            RuleMatcher::matchAll('disk.*.used_pct', ['disk./.used_pct', 'cpu.temp_c', 'disk./mnt/backup.used_pct', 'disk./.free_b']),
        );
    }
}
