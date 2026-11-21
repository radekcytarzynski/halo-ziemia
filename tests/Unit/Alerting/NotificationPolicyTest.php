<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\NotificationKind;
use App\Alerting\NotificationPolicy;
use App\Enum\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationPolicyTest extends TestCase
{
    private const string NOW = '2026-01-01 12:00:00';

    #[DataProvider('provider')]
    public function testDecide(Severity $severity, ?Severity $notified, ?string $lastNotifiedAt, int $interval, bool $muted, NotificationKind $expected): void
    {
        $kind = (new NotificationPolicy())->decide(
            $severity,
            $notified,
            null === $lastNotifiedAt ? null : new \DateTimeImmutable($lastNotifiedAt),
            $interval,
            $muted,
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame($expected, $kind);
    }

    /**
     * @return iterable<string, array{Severity, ?Severity, ?string, int, bool, NotificationKind}>
     */
    public static function provider(): iterable
    {
        yield 'wszystko OK' => [Severity::Ok, null, null, 21600, false, NotificationKind::None];
        yield 'nowy problem' => [Severity::Crit, null, null, 21600, false, NotificationKind::Change];
        yield 'eskalacja' => [Severity::Crit, Severity::Warn, '2026-01-01 11:59:00', 21600, false, NotificationKind::Change];
        yield 'deeskalacja' => [Severity::Warn, Severity::Crit, '2026-01-01 11:59:00', 21600, false, NotificationKind::Change];
        yield 'odzyskanie' => [Severity::Ok, Severity::Down, '2026-01-01 11:00:00', 21600, false, NotificationKind::Change];
        yield 'już zgłoszone, przed przypomnieniem' => [Severity::Crit, Severity::Crit, '2026-01-01 07:00:00', 21600, false, NotificationKind::None];
        yield 'przypomnienie po 6 h' => [Severity::Crit, Severity::Crit, '2026-01-01 06:00:00', 21600, false, NotificationKind::Reminder];
        yield 'przypomnienia wyłączone' => [Severity::Crit, Severity::Crit, '2026-01-01 00:00:00', 0, false, NotificationKind::None];
        yield 'wyciszone: brak zmian' => [Severity::Crit, null, null, 21600, true, NotificationKind::None];
        yield 'wyciszone: brak przypomnień' => [Severity::Crit, Severity::Crit, '2026-01-01 00:00:00', 21600, true, NotificationKind::None];
        yield 'po wyciszeniu: nadrobienie problemu' => [Severity::Warn, null, null, 21600, false, NotificationKind::Change];
        yield 'po wyciszeniu: problem minął w trakcie' => [Severity::Ok, Severity::Crit, '2026-01-01 08:00:00', 21600, false, NotificationKind::Change];
        yield 'problem i powrót w trakcie wyciszenia' => [Severity::Ok, null, null, 21600, false, NotificationKind::None];
    }
}
