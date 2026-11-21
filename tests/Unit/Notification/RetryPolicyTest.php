<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    #[DataProvider('delayProvider')]
    public function testDelay(int $attempts, int $expectedDelay): void
    {
        self::assertSame($expectedDelay, (new RetryPolicy())->delayAfter($attempts));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function delayProvider(): iterable
    {
        yield 'po 1. próbie' => [1, 60];
        yield 'po 2. próbie' => [2, 120];
        yield 'po 6. próbie' => [6, 3600];
        yield 'dalej co godzinę' => [30, 3600];
    }

    public function testGiveUp(): void
    {
        $policy = new RetryPolicy();

        self::assertFalse($policy->shouldGiveUp(RetryPolicy::MAX_ATTEMPTS - 1));
        self::assertTrue($policy->shouldGiveUp(RetryPolicy::MAX_ATTEMPTS));
    }

    public function testNextAttemptAt(): void
    {
        self::assertEquals(
            new \DateTimeImmutable('2026-01-01 12:02:00'),
            (new RetryPolicy())->nextAttemptAt(new \DateTimeImmutable('2026-01-01 12:00:00'), 2),
        );
    }
}
