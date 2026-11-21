<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\Severity;

final readonly class StateDecision
{
    public function __construct(
        public Severity $severity,
        public ?Severity $candidate,
        public int $candidateCount,
        public bool $changed,
    ) {
    }
}
