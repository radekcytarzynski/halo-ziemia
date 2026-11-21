<?php

declare(strict_types=1);

namespace App\Alerting;

/**
 * Urządzenie jest DOWN, gdy: now > last_seen + interval × missed_threshold + grace.
 */
final class HeartbeatEvaluator
{
    public function deadline(\DateTimeImmutable $lastSeenAt, int $intervalSeconds, int $missedThreshold, int $graceSeconds): \DateTimeImmutable
    {
        $seconds = max(1, $intervalSeconds) * max(1, $missedThreshold) + max(0, $graceSeconds);

        return $lastSeenAt->modify(\sprintf('+%d seconds', $seconds));
    }

    public function isDown(\DateTimeImmutable $lastSeenAt, int $intervalSeconds, int $missedThreshold, int $graceSeconds, \DateTimeImmutable $now): bool
    {
        return $now > $this->deadline($lastSeenAt, $intervalSeconds, $missedThreshold, $graceSeconds);
    }
}
