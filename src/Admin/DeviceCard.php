<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Device;

/**
 * Dane karty urządzenia na dashboardzie.
 */
final readonly class DeviceCard
{
    /**
     * @param list<array{label: string, value: float, unit: string|null, severity: string|null}> $indicators
     * @param list<string>                                                                       $problems
     */
    public function __construct(
        public Device $device,
        public array $indicators,
        public array $problems,
        public ?string $sparklinePath,
        public ?string $sparklineLabel,
        public bool $muted,
    ) {
    }
}
