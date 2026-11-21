<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\OutboxMessage;
use App\Enum\NotificationChannel;
use Psr\Clock\ClockInterface;

/**
 * Natychmiastowa wysyłka wiadomości testowej (z pominięciem outboxa), żeby od razu pokazać błąd konfiguracji.
 */
final readonly class TestNotificationSender
{
    public function __construct(
        private NotifierRegistry $notifiers,
        private AlertMessageRenderer $renderer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotificationFailedException
     */
    public function send(NotificationChannel $channel, string $address): void
    {
        $now = $this->clock->now();
        $rendered = $this->renderer->renderTest($now);
        $message = new OutboxMessage(null, null, $channel, $address, $rendered->subject, $rendered->text, $rendered->html, $now);

        $this->notifiers->get($channel)->send($message);
    }
}
