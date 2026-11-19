<?php

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\Device;
use App\Entity\Event;
use App\Enum\EventType;
use App\Enum\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Zapis zdarzeń osi czasu. Tylko persist – flush należy do wywołującego (wspólna transakcja).
 */
final readonly class EventRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, scalar|null>|null $context
     */
    public function record(
        ?Device $device,
        EventType $type,
        string $message,
        ?Severity $severity = null,
        ?array $context = null,
        ?\DateTimeImmutable $at = null,
    ): Event {
        $event = new Event($device, $type, $message, $at ?? $this->clock->now(), $severity, $context);
        $this->entityManager->persist($event);

        return $event;
    }
}
