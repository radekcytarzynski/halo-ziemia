<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\EventType;
use App\Enum\Severity;
use App\Repository\OutboxMessageRepository;
use App\Timeline\EventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wysyłka zaległych wiadomości z outboxa. Każda wiadomość jest zapisywana osobno (flush),
 * więc przerwanie procesu nie powoduje podwójnej wysyłki całej paczki ani zgubienia alarmu.
 */
final readonly class OutboxProcessor
{
    public const int BATCH_SIZE = 50;

    public function __construct(
        private OutboxMessageRepository $outbox,
        private NotifierRegistry $notifiers,
        private RetryPolicy $retryPolicy,
        private EventRecorder $events,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function process(\DateTimeImmutable $now, int $limit = self::BATCH_SIZE): OutboxResult
    {
        $sent = $retrying = $failed = 0;

        foreach ($this->outbox->findDue($now, $limit) as $message) {
            try {
                $this->notifiers->get($message->getChannel())->send($message);
                $message->markSent($now);
                $this->events->record($message->getDevice(), EventType::NotificationSent, \sprintf('%s → %s: %s', $message->getChannel()->label(), $message->getAddress(), $message->getSubject()), at: $now);
                ++$sent;
            } catch (NotificationFailedException $e) {
                $attempts = $message->getAttempts() + 1;
                $giveUp = $this->retryPolicy->shouldGiveUp($attempts);
                $message->markAttemptFailed($e->getMessage(), $this->retryPolicy->nextAttemptAt($now, $attempts), $giveUp);
                $this->logger->warning('Nie udało się wysłać powiadomienia #{id} (próba {attempt}): {error}', [
                    'id' => $message->getId(),
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($giveUp) {
                    $this->events->record($message->getDevice(), EventType::NotificationFailed, \sprintf('Porzucono po %d próbach: %s → %s (%s)', $attempts, $message->getAddress(), $message->getSubject(), mb_substr($e->getMessage(), 0, 200)), Severity::Crit, at: $now);
                    ++$failed;
                } else {
                    ++$retrying;
                }
            }

            $this->entityManager->flush();
        }

        return new OutboxResult($sent, $retrying, $failed);
    }
}
