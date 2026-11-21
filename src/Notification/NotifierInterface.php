<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\OutboxMessage;
use App\Enum\NotificationChannel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Kanał powiadomień. Nowy kanał (np. Telegram) = nowy case w NotificationChannel
 * + implementacja tego interfejsu; logika alarmów i outbox pozostają bez zmian.
 */
#[AutoconfigureTag(self::TAG)]
interface NotifierInterface
{
    public const string TAG = 'app.notifier';

    public function channel(): NotificationChannel;

    /**
     * @throws NotificationFailedException
     */
    public function send(OutboxMessage $message): void;
}
