<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\RuleOperator;
use App\Enum\Severity;

/**
 * Ocena wartości metryki względem progów warn/crit z histerezą.
 *
 * Próg jest „przekroczony”, gdy (wartość OPERATOR próg) jest prawdą, np. 91 > 90.
 * Histereza: aby stan się poprawił, wartość musi wrócić za próg o zadany margines
 * (dla > / ≥ poniżej próg − margines, dla < / ≤ powyżej próg + margines; dla = i ≠ brak histerezy).
 */
final class RuleEvaluator
{
    public function evaluate(
        RuleOperator $operator,
        ?float $warn,
        ?float $crit,
        float $hysteresis,
        float $value,
        Severity $current,
    ): Severity {
        $raw = $this->severityAt($operator, $warn, $crit, $value, 0.0);
        if (!$current->isWorseThan($raw) || $hysteresis <= 0.0 || Severity::Down === $current) {
            return $raw;
        }

        // Wartość poprawiła się, sprawdzamy czy wyszła poza pas histerezy aktualnego stanu.
        $held = $this->severityAt($operator, $warn, $crit, $value, $hysteresis);
        if ($held->isWorseThan($current)) {
            $held = $current;
        }

        return Severity::worst($raw, $held);
    }

    public static function breaches(RuleOperator $operator, float $value, float $threshold, float $margin = 0.0): bool
    {
        return match ($operator) {
            RuleOperator::Gt => $value > $threshold - $margin,
            RuleOperator::Gte => $value >= $threshold - $margin,
            RuleOperator::Lt => $value < $threshold + $margin,
            RuleOperator::Lte => $value <= $threshold + $margin,
            RuleOperator::Eq => abs($value - $threshold) < \PHP_FLOAT_EPSILON,
            RuleOperator::Ne => abs($value - $threshold) >= \PHP_FLOAT_EPSILON,
        };
    }

    private function severityAt(RuleOperator $operator, ?float $warn, ?float $crit, float $value, float $margin): Severity
    {
        if (null !== $crit && self::breaches($operator, $value, $crit, $margin)) {
            return Severity::Crit;
        }
        if (null !== $warn && self::breaches($operator, $value, $warn, $margin)) {
            return Severity::Warn;
        }

        return Severity::Ok;
    }
}
