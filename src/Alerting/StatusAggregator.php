<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\DeviceStatus;
use App\Enum\Severity;

/**
 * Status urządzenia = najgorszy ze stanów składowych.
 * Wyłączone → PAUSED, bez żadnego kontaktu → PENDING. Tryb serwisowy nie zmienia statusu (tylko wycisza).
 */
final class StatusAggregator
{
    /**
     * @param list<Severity> $componentSeverities
     */
    public function aggregate(bool $enabled, bool $everSeen, array $componentSeverities): DeviceStatus
    {
        if (!$enabled) {
            return DeviceStatus::Paused;
        }
        if (!$everSeen) {
            return DeviceStatus::Pending;
        }

        return DeviceStatus::fromSeverity(Severity::worst(...$componentSeverities));
    }
}
