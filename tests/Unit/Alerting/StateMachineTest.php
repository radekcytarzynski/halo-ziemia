<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\StateMachine;
use App\Enum\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    #[DataProvider('provider')]
    public function testObserve(
        Severity $current,
        ?Severity $candidate,
        int $count,
        Severity $observed,
        int $required,
        Severity $expectedSeverity,
        ?Severity $expectedCandidate,
        int $expectedCount,
        bool $expectedChanged,
    ): void {
        $decision = (new StateMachine())->observe($current, $candidate, $count, $observed, $required);

        self::assertSame($expectedSeverity, $decision->severity);
        self::assertSame($expectedCandidate, $decision->candidate);
        self::assertSame($expectedCount, $decision->candidateCount);
        self::assertSame($expectedChanged, $decision->changed);
    }

    /**
     * @return iterable<string, array{Severity, ?Severity, int, Severity, int, Severity, ?Severity, int, bool}>
     */
    public static function provider(): iterable
    {
        yield 'bez zmian' => [Severity::Ok, null, 0, Severity::Ok, 3, Severity::Ok, null, 0, false];
        yield 'natychmiastowa zmiana (N=1)' => [Severity::Ok, null, 0, Severity::Crit, 1, Severity::Crit, null, 0, true];
        yield 'pierwsza obserwacja kandydata' => [Severity::Ok, null, 0, Severity::Warn, 3, Severity::Ok, Severity::Warn, 1, false];
        yield 'druga obserwacja kandydata' => [Severity::Ok, Severity::Warn, 1, Severity::Warn, 3, Severity::Ok, Severity::Warn, 2, false];
        yield 'trzecia = zmiana' => [Severity::Ok, Severity::Warn, 2, Severity::Warn, 3, Severity::Warn, null, 0, true];
        yield 'flapping resetuje licznik' => [Severity::Ok, Severity::Warn, 2, Severity::Ok, 3, Severity::Ok, null, 0, false];
        yield 'inny kandydat zaczyna od 1' => [Severity::Ok, Severity::Warn, 2, Severity::Crit, 3, Severity::Ok, Severity::Crit, 1, false];
        yield 'powrót też wymaga N' => [Severity::Crit, null, 0, Severity::Ok, 2, Severity::Crit, Severity::Ok, 1, false];
        yield 'powrót po N' => [Severity::Crit, Severity::Ok, 1, Severity::Ok, 2, Severity::Ok, null, 0, true];
        yield 'N<1 traktowane jak 1' => [Severity::Ok, null, 0, Severity::Down, 0, Severity::Down, null, 0, true];
    }
}
