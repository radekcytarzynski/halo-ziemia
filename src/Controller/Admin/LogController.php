<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\OutboxMessage;
use App\Enum\EventType;
use App\Enum\OutboxStatus;
use App\Enum\Severity;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Repository\OutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/log')]
final class LogController extends AbstractController
{
    public const int PER_PAGE = 50;

    #[Route('', name: 'app_log_events', methods: ['GET'])]
    public function events(Request $request, EventRepository $events, DeviceRepository $devices): Response
    {
        $deviceId = $request->query->getInt('device');
        $device = $deviceId > 0 ? $devices->find($deviceId) : null;
        $type = EventType::tryFrom($request->query->getString('type'));
        $severity = Severity::tryFrom($request->query->getString('severity'));
        $page = max(1, $request->query->getInt('page', 1));

        $result = $events->search($device, $type, $severity, $page, self::PER_PAGE);

        return $this->render('admin/log/events.html.twig', [
            'events' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'devices' => $devices->findBy([], ['name' => 'ASC']),
            'types' => EventType::cases(),
            'severities' => Severity::cases(),
            'filter' => ['device' => $device?->getId(), 'type' => $type?->value, 'severity' => $severity?->value],
        ]);
    }

    #[Route('/notifications', name: 'app_log_notifications', methods: ['GET'])]
    public function notifications(Request $request, OutboxMessageRepository $outbox): Response
    {
        $status = OutboxStatus::tryFrom($request->query->getString('status'));
        $page = max(1, $request->query->getInt('page', 1));
        $result = $outbox->search($status, $page, self::PER_PAGE);

        return $this->render('admin/log/notifications.html.twig', [
            'messages' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'statuses' => OutboxStatus::cases(),
            'filter' => ['status' => $status?->value],
        ]);
    }

    #[Route('/notifications/{id}/retry', name: 'app_log_notification_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(OutboxMessage $message, Request $request, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        if (!$this->isCsrfTokenValid('outbox'.$message->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Nieprawidłowy token CSRF.');
        }

        $message->requeue($clock->now());
        $entityManager->flush();
        $this->addFlash('success', 'Wiadomość wróciła do kolejki – zostanie wysłana przy najbliższym app:tick.');

        return $this->redirectToRoute('app_log_notifications');
    }
}
