<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\RecipientRouter;
use App\Alerting\RouteTarget;
use App\Enum\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecipientRouterTest extends TestCase
{
    /**
     * @param array<int, array{from: Severity, to: Severity}>               $changes
     * @param array<int, array{problems: list<int>, recoveries: list<int>}> $expected
     */
    #[DataProvider('changesProvider')]
    public function testRouteChanges(Severity $minSeverity, bool $notifyRecovery, array $changes, array $expected): void
    {
        $routes = (new RecipientRouter())->routeChanges([new RouteTarget(7, $minSeverity, $notifyRecovery)], $changes);

        self::assertSame($expected, $routes);
    }

    /**
     * @return iterable<string, array{Severity, bool, array<int, array{from: Severity, to: Severity}>, array<int, array{problems: list<int>, recoveries: list<int>}>}>
     */
    public static function changesProvider(): iterable
    {
        yield 'WARN dla subskrybenta od WARN' => [Severity::Warn, true, [['from' => Severity::Ok, 'to' => Severity::Warn]], [7 => ['problems' => [0], 'recoveries' => []]]];
        yield 'WARN pomijany dla subskrybenta od CRIT' => [Severity::Crit, true, [['from' => Severity::Ok, 'to' => Severity::Warn]], []];
        yield 'DOWN liczy się jak CRIT' => [Severity::Crit, true, [['from' => Severity::Ok, 'to' => Severity::Down]], [7 => ['problems' => [0], 'recoveries' => []]]];
        yield 'odzyskanie' => [Severity::Warn, true, [['from' => Severity::Crit, 'to' => Severity::Ok]], [7 => ['problems' => [], 'recoveries' => [0]]]];
        yield 'odzyskanie wyłączone' => [Severity::Warn, false, [['from' => Severity::Crit, 'to' => Severity::Ok]], []];
        yield 'CRIT → WARN = odzyskanie dla subskrybenta od CRIT' => [Severity::Crit, true, [['from' => Severity::Crit, 'to' => Severity::Warn]], [7 => ['problems' => [], 'recoveries' => [0]]]];
        yield 'WARN → OK niewidoczne dla subskrybenta od CRIT' => [Severity::Crit, true, [['from' => Severity::Warn, 'to' => Severity::Ok]], []];
        yield 'mieszane' => [Severity::Warn, true, [
            ['from' => Severity::Ok, 'to' => Severity::Crit],
            ['from' => Severity::Warn, 'to' => Severity::Ok],
        ], [7 => ['problems' => [0], 'recoveries' => [1]]]];
    }

    public function testMultipleSubscriptionsOfSameRecipientAreMerged(): void
    {
        $routes = (new RecipientRouter())->routeChanges(
            [new RouteTarget(1, Severity::Crit, false), new RouteTarget(1, Severity::Warn, true), new RouteTarget(2, Severity::Crit, true)],
            [['from' => Severity::Ok, 'to' => Severity::Warn]],
        );

        self::assertSame([1 => ['problems' => [0], 'recoveries' => []]], $routes);
    }

    public function testReminders(): void
    {
        $routes = (new RecipientRouter())->routeReminders(
            [new RouteTarget(1, Severity::Warn, true), new RouteTarget(2, Severity::Crit, true)],
            [0 => Severity::Warn, 1 => Severity::Down],
        );

        self::assertSame([1 => [0, 1], 2 => [1]], $routes);
    }
}
