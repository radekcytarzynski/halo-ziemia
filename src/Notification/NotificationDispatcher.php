<?php

declare(strict_types=1);

namespace App\Notification;

use App\Alerting\AlertItem;
use App\Alerting\RecipientRouter;
use App\Alerting\RouteTarget;
use App\Entity\Device;
use App\Entity\OutboxMessage;
use App\Entity\Recipient;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wybiera odbiorców (subskrypcje), renderuje treść i kolejkuje wiadomości w outboxie.
 * Nie wysyła – wysyłką zajmuje się OutboxProcessor (z retry).
 */
final readonly class NotificationDispatcher
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private RecipientRouter $router,
        private AlertMessageRenderer $renderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<AlertItem> $changes from = ostatnio zgłoszony stan, to = bieżący
     *
     * @return int liczba zakolejkowanych wiadomości
     */
    public function dispatchChanges(Device $device, array $changes, \DateTimeImmutable $now): int
    {
        if ([] === $changes) {
            return 0;
        }

        [$targets, $recipients] = $this->targets($device);
        $routes = $this->router->routeChanges($targets, array_map(
            static fn (AlertItem $i): array => ['from' => $i->from, 'to' => $i->to],
            $changes,
        ));

        $queued = 0;
        foreach ($routes as $recipientId => $route) {
            $message = $this->renderer->renderChange(
                $device,
                array_map(static fn (int $i): AlertItem => $changes[$i], $route['problems']),
                array_map(static fn (int $i): AlertItem => $changes[$i], $route['recoveries']),
                $now,
            );
            $this->enqueue($recipients[$recipientId], $device, $message, $now);
            ++$queued;
        }

        return $queued;
    }

    /**
     * @param list<AlertItem> $items trwające problemy
     */
    public function dispatchReminders(Device $device, array $items, \DateTimeImmutable $now): int
    {
        if ([] === $items) {
            return 0;
        }

        [$targets, $recipients] = $this->targets($device);
        $routes = $this->router->routeReminders($targets, array_map(static fn (AlertItem $i) => $i->to, $items));

        $queued = 0;
        foreach ($routes as $recipientId => $indexes) {
            $message = $this->renderer->renderReminder($device, array_map(static fn (int $i): AlertItem => $items[$i], $indexes), $now);
            $this->enqueue($recipients[$recipientId], $device, $message, $now);
            ++$queued;
        }

        return $queued;
    }

    /**
     * @return array{list<RouteTarget>, array<int, Recipient>}
     */
    private function targets(Device $device): array
    {
        $targets = [];
        $recipients = [];
        foreach ($this->subscriptions->findActiveForDevice($device) as $subscription) {
            $recipient = $subscription->getRecipient();
            $id = $recipient->getId();
            if (null === $id) {
                continue;
            }
            $recipients[$id] = $recipient;
            $targets[] = new RouteTarget($id, $subscription->getMinSeverity(), $subscription->isNotifyRecovery());
        }

        return [$targets, $recipients];
    }

    private function enqueue(Recipient $recipient, Device $device, RenderedMessage $message, \DateTimeImmutable $now): void
    {
        $this->entityManager->persist(new OutboxMessage(
            $recipient,
            $device,
            $recipient->getChannel(),
            $recipient->getAddress(),
            $message->subject,
            $message->text,
            $message->html,
            $now,
        ));
    }
}
