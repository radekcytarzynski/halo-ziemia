<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Ingest\HeartbeatReceived;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ocena reguł przy odbiorze heartbeatu. Błąd silnika nie może odrzucić heartbeatu
 * (dane są już zapisane) – logujemy go, a agent dostaje 200.
 */
#[AsEventListener]
final readonly class HeartbeatListener
{
    public function __construct(
        private AlertEngine $engine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HeartbeatReceived $event): void
    {
        try {
            $this->engine->onHeartbeat($event->device, $event->heartbeat, $event->receivedAt);
        } catch (\Throwable $e) {
            $this->logger->error('Błąd oceny alarmów dla urządzenia {device}: {message}', [
                'device' => $event->device->getSlug(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
