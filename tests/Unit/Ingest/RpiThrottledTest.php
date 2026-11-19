<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingest;

use App\Ingest\Payload\RpiThrottled;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RpiThrottledTest extends TestCase
{
    #[DataProvider('parseProvider')]
    public function testParse(string $raw, ?int $expected): void
    {
        self::assertSame($expected, RpiThrottled::parse($raw));
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function parseProvider(): iterable
    {
        yield 'zero' => ['0x0', 0];
        yield 'undervoltage teraz i w przeszłości' => ['0x50005', 0x50005];
        yield 'wielkie litery' => ['0xABC', 0xABC];
        yield 'bez prefiksu' => ['50005', null];
        yield 'śmieci' => ['0xZZ', null];
        yield 'za długi' => ['0x123456789', null];
    }

    public function testDescribe(): void
    {
        self::assertSame(
            ['Niskie napięcie (teraz)', 'Dławienie (teraz)', 'Niskie napięcie (wystąpiło)', 'Dławienie (wystąpiło)'],
            RpiThrottled::describe(0x50005),
        );
        self::assertSame([], RpiThrottled::describe(0));
    }
}
