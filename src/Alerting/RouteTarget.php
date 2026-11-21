<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\Severity;

/**
 * Subskrypcja sprowadzona do tego, co potrzebne do routingu (czysta logika).
 */
final readonly class RouteTarget
{
    public function __construct(
        public int $recipientId,
        public Severity $minSeverity,
        public bool $notifyRecovery,
    ) {
    }
}
