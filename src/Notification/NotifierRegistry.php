<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationChannel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class NotifierRegistry
{
    /** @var array<string, NotifierInterface> */
    private array $notifiers;

    /**
     * @param iterable<NotifierInterface> $notifiers
     */
    public function __construct(#[AutowireIterator(NotifierInterface::TAG)] iterable $notifiers)
    {
        $map = [];
        foreach ($notifiers as $notifier) {
            $map[$notifier->channel()->value] = $notifier;
        }
        $this->notifiers = $map;
    }

    public function get(NotificationChannel $channel): NotifierInterface
    {
        return $this->notifiers[$channel->value]
            ?? throw new NotificationFailedException(\sprintf('Brak obsługi kanału "%s".', $channel->value));
    }
}
