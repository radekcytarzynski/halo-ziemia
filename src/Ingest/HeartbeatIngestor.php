<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Entity\CheckResult;
use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Enum\CheckStatus;
use App\Ingest\Payload\ParsedHeartbeat;
use App\Metrics\MetricRegistry;
use App\Metrics\SampleWriter;
use App\Repository\CheckResultRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Zapis heartbeatu: czas kontaktu (zawsze czas serwera), snapshot, próbki, wyniki checków.
 */
final readonly class HeartbeatIngestor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MetricRegistry $metrics,
        private SampleWriter $samples,
        private CheckResultRepository $checkResults,
        private EventDispatcherInterface $dispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function ingest(DeviceToken $token, ?ParsedHeartbeat $heartbeat, ?string $remoteIp): \DateTimeImmutable
    {
        $now = $this->clock->now();
        $device = $token->getDevice();

        $this->entityManager->wrapInTransaction(function () use ($device, $token, $heartbeat, $remoteIp, $now): void {
            $device->setLastSeenAt($now);
            $device->setLastIp(null === $remoteIp ? null : mb_substr($remoteIp, 0, 45));
            $token->markUsed($now);

            if (null !== $heartbeat) {
                $device->setLastAgentVersion($heartbeat->agentVersion);
                $device->setLastSnapshot($heartbeat->snapshot);
                $this->writeSamples($device, $heartbeat, $now);
                $this->storeChecks($device, $heartbeat, $now);
            }
        });

        $this->dispatcher->dispatch(new HeartbeatReceived($device, $heartbeat, $now));

        return $now;
    }

    private function writeSamples(Device $device, ParsedHeartbeat $heartbeat, \DateTimeImmutable $now): void
    {
        if ([] === $heartbeat->metrics) {
            return;
        }

        $deviceId = $device->getId() ?? throw new \LogicException('Urządzenie nie jest zapisane.');
        $ids = $this->metrics->idsFor(array_map('strval', array_keys($heartbeat->metrics)));

        $values = [];
        foreach ($heartbeat->metrics as $key => $value) {
            $values[$ids[$key]] = $value;
        }

        $this->samples->write($deviceId, $now->getTimestamp(), $values);
    }

    private function storeChecks(Device $device, ParsedHeartbeat $heartbeat, \DateTimeImmutable $now): void
    {
        if ([] === $heartbeat->checks) {
            return;
        }

        $existing = [];
        foreach ($this->checkResults->findBy(['device' => $device]) as $result) {
            $existing[$result->getName()] = $result;
        }

        foreach ($heartbeat->checks as $report) {
            $result = $existing[$report->name] ?? null;

            $lastSuccessAt = match (true) {
                null !== $report->lastSuccessAgeSeconds => $now->modify(\sprintf('-%d seconds', $report->lastSuccessAgeSeconds)),
                CheckStatus::Ok === $report->status => $now,
                default => $result?->getLastSuccessAt(),
            };

            if (null === $result) {
                $result = new CheckResult($device, $report->name, $report->status, $now);
                $this->entityManager->persist($result);
            }
            $result->update($report->status, $report->message, $lastSuccessAt, $now);
        }
    }
}
