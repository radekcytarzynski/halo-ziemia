<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\Severity;

/**
 * Anty-flapping: stan zmienia się dopiero, gdy ta sama obserwacja wystąpi
 * $required razy z rzędu. Dotyczy zarówno pogorszenia, jak i powrotu do OK.
 */
final class StateMachine
{
    public function observe(Severity $current, ?Severity $candidate, int $candidateCount, Severity $observed, int $required): StateDecision
    {
        $required = max(1, $required);

        if ($observed === $current) {
            return new StateDecision($current, null, 0, false);
        }

        $count = $observed === $candidate ? $candidateCount + 1 : 1;
        if ($count >= $required) {
            return new StateDecision($observed, null, 0, true);
        }

        return new StateDecision($current, $observed, $count, false);
    }
}
