<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\RuleEvaluator;
use App\Enum\RuleOperator;
use App\Enum\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleEvaluatorTest extends TestCase
{
    #[DataProvider('provider')]
    public function testEvaluate(RuleOperator $operator, ?float $warn, ?float $crit, float $hysteresis, float $value, Severity $current, Severity $expected): void
    {
        self::assertSame($expected, (new RuleEvaluator())->evaluate($operator, $warn, $crit, $hysteresis, $value, $current));
    }

    /**
     * @return iterable<string, array{RuleOperator, ?float, ?float, float, float, Severity, Severity}>
     */
    public static function provider(): iterable
    {
        // Progi "więcej = gorzej" (dysk %)
        yield 'poniżej warn' => [RuleOperator::Gt, 85.0, 95.0, 0.0, 50.0, Severity::Ok, Severity::Ok];
        yield 'równo progowi (>) to jeszcze OK' => [RuleOperator::Gt, 85.0, 95.0, 0.0, 85.0, Severity::Ok, Severity::Ok];
        yield 'równo progowi (≥) to WARN' => [RuleOperator::Gte, 85.0, 95.0, 0.0, 85.0, Severity::Ok, Severity::Warn];
        yield 'powyżej warn' => [RuleOperator::Gt, 85.0, 95.0, 0.0, 90.0, Severity::Ok, Severity::Warn];
        yield 'powyżej crit' => [RuleOperator::Gt, 85.0, 95.0, 0.0, 96.0, Severity::Ok, Severity::Crit];
        yield 'tylko crit' => [RuleOperator::Gt, null, 0.0, 0.0, 1.0, Severity::Ok, Severity::Crit];
        yield 'brak progów' => [RuleOperator::Gt, null, null, 0.0, 1000.0, Severity::Ok, Severity::Ok];

        // Progi "mniej = gorzej" (wolna pamięć %)
        yield 'pamięć OK' => [RuleOperator::Lt, 10.0, 5.0, 0.0, 30.0, Severity::Ok, Severity::Ok];
        yield 'pamięć WARN' => [RuleOperator::Lt, 10.0, 5.0, 0.0, 8.0, Severity::Ok, Severity::Warn];
        yield 'pamięć CRIT' => [RuleOperator::Lt, 10.0, 5.0, 0.0, 4.0, Severity::Ok, Severity::Crit];
        yield 'pamięć ≤' => [RuleOperator::Lte, 10.0, 5.0, 0.0, 5.0, Severity::Ok, Severity::Crit];

        // Równość / różność (SMART passed, throttled)
        yield 'ne: 0 → OK' => [RuleOperator::Ne, 0.0, null, 0.0, 0.0, Severity::Ok, Severity::Ok];
        yield 'ne: 5 → WARN' => [RuleOperator::Ne, 0.0, null, 0.0, 5.0, Severity::Ok, Severity::Warn];
        yield 'eq: 1 → CRIT' => [RuleOperator::Eq, null, 1.0, 0.0, 1.0, Severity::Ok, Severity::Crit];

        // Histereza: powrót dopiero za progiem − margines
        yield 'hist: WARN trzyma w pasie' => [RuleOperator::Gt, 85.0, 95.0, 2.0, 84.0, Severity::Warn, Severity::Warn];
        yield 'hist: WARN puszcza poza pasem' => [RuleOperator::Gt, 85.0, 95.0, 2.0, 82.9, Severity::Warn, Severity::Ok];
        yield 'hist: CRIT trzyma w pasie' => [RuleOperator::Gt, 85.0, 95.0, 2.0, 94.0, Severity::Crit, Severity::Crit];
        yield 'hist: CRIT → WARN poza pasem crit' => [RuleOperator::Gt, 85.0, 95.0, 2.0, 92.0, Severity::Crit, Severity::Warn];
        yield 'hist: CRIT → OK od razu przy dużym spadku' => [RuleOperator::Gt, 85.0, 95.0, 2.0, 50.0, Severity::Crit, Severity::Ok];
        yield 'hist: nie podnosi stanu z WARN do CRIT w pasie' => [RuleOperator::Gt, 85.0, 95.0, 2.0, 94.0, Severity::Warn, Severity::Warn];
        yield 'hist: pogorszenie bez histerezy' => [RuleOperator::Gt, 85.0, 95.0, 2.0, 96.0, Severity::Warn, Severity::Crit];
        yield 'hist: mniej = gorzej trzyma' => [RuleOperator::Lt, 10.0, 5.0, 2.0, 11.0, Severity::Warn, Severity::Warn];
        yield 'hist: mniej = gorzej puszcza' => [RuleOperator::Lt, 10.0, 5.0, 2.0, 12.5, Severity::Warn, Severity::Ok];
    }
}
