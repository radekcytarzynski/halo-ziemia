<?php

declare(strict_types=1);

namespace App\Ingest\Payload;

/**
 * Zwalidowany i spłaszczony heartbeat.
 */
final readonly class ParsedHeartbeat
{
    /**
     * @param array<string, float> $metrics  klucz metryki → wartość numeryczna
     * @param list<CheckReport>    $checks
     * @param array<string, mixed> $snapshot dane do podglądu (bez surowego payloadu)
     */
    public function __construct(
        public ?string $agentVersion,
        public ?string $hostname,
        public array $metrics,
        public array $checks,
        public array $snapshot,
    ) {
    }
}
