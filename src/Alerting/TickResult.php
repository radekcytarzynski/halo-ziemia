<?php

declare(strict_types=1);

namespace App\Alerting;

final readonly class TickResult
{
    public function __construct(
        public int $devicesEvaluated,
        public int $devicesDown,
        public int $notificationsQueued,
    ) {
    }
}
