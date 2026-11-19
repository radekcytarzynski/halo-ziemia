<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Device;
use App\Entity\DeviceToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceTokenTest extends TestCase
{
    #[DataProvider('validityProvider')]
    public function testValidity(?string $expiresAt, ?string $revokedAt, string $now, bool $expected): void
    {
        $created = new \DateTimeImmutable('2026-01-01 00:00:00');
        $token = new DeviceToken(new Device('NAS', 'nas', $created), 'hz_abcdef', str_repeat('a', 64), $created);

        if (null !== $expiresAt) {
            $token->expireAt(new \DateTimeImmutable($expiresAt));
        }
        if (null !== $revokedAt) {
            $token->revoke(new \DateTimeImmutable($revokedAt));
        }

        self::assertSame($expected, $token->isValidAt(new \DateTimeImmutable($now)));
    }

    /**
     * @return iterable<string, array{?string, ?string, string, bool}>
     */
    public static function validityProvider(): iterable
    {
        yield 'aktywny' => [null, null, '2026-06-01 00:00:00', true];
        yield 'unieważniony' => [null, '2026-02-01 00:00:00', '2026-06-01 00:00:00', false];
        yield 'w oknie rotacji' => ['2026-06-01 01:00:00', null, '2026-06-01 00:59:59', true];
        yield 'po oknie rotacji' => ['2026-06-01 01:00:00', null, '2026-06-01 01:00:00', false];
    }

    public function testRevokeKeepsFirstTimestamp(): void
    {
        $created = new \DateTimeImmutable('2026-01-01 00:00:00');
        $token = new DeviceToken(new Device('NAS', 'nas', $created), 'hz_abcdef', str_repeat('a', 64), $created);

        $token->revoke(new \DateTimeImmutable('2026-02-01 00:00:00'));
        $token->revoke(new \DateTimeImmutable('2026-03-01 00:00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-02-01 00:00:00'), $token->getRevokedAt());
    }
}
