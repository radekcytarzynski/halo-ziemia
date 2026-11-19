<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Entity\Device;
use App\Ingest\Payload\ParsedHeartbeat;

/**
 * Zdarzenie po zapisaniu heartbeatu (po commicie). Silnik alarmów (etap 3) ocenia tu reguły.
 * $heartbeat === null oznacza minimalny ping bez metryk.
 */
final readonly class HeartbeatReceived
{
    public function __construct(
        public Device $device,
        public ?ParsedHeartbeat $heartbeat,
        public \DateTimeImmutable $receivedAt,
    ) {
    }
}
