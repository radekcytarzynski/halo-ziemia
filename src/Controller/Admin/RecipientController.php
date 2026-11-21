<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Form\RecipientType;
use App\Admin\Form\SubscriptionType;
use App\Entity\Recipient;
use App\Entity\Subscription;
use App\Enum\EventType;
use App\Enum\NotificationChannel;
use App\Notification\NotificationFailedException;
use App\Notification\TestNotificationSender;
use App\Repository\RecipientRepository;
use App\Repository\SubscriptionRepository;
use App\Timeline\EventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/recipients')]
final class RecipientController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRecorder $events,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_recipient_index', methods: ['GET'])]
    public function index(RecipientRepository $recipients, SubscriptionRepository $subscriptions): Response
    {
        $byRecipient = [];
        foreach ($subscriptions->findAll() as $subscription) {
            $recipientId = $subscription->getRecipient()->getId();
            if (null !== $recipientId) {
                $byRecipient[$recipientId][] = $subscription;
            }
        }

        return $this->render('admin/recipient/index.html.twig', [
            'recipients' => $recipients->findBy([], ['name' => 'ASC']),
            'subscriptions' => $byRecipient,
        ]);
    }

    #[Route('/new', name: 'app_recipient_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $recipient = new Recipient('', NotificationChannel::Email, '', $this->clock->now());
        $form = $this->createForm(RecipientType::class, $recipient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($recipient);
            // Domyślnie: wszystkie urządzenia od WARN, z „odzyskano”.
            $this->entityManager->persist(new Subscription($recipient, null));
            $this->events->record(null, EventType::ConfigChange, \sprintf('Dodano odbiorcę %s', $recipient->getAddress()));
            $this->entityManager->flush();
            $this->addFlash('success', 'Dodano odbiorcę z subskrypcją wszystkich urządzeń (od WARN). Możesz ją zmienić poniżej.');

            return $this->redirectToRoute('app_recipient_edit', ['id' => $recipient->getId()]);
        }

        return $this->render('admin/recipient/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'app_recipient_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Recipient $recipient, Request $request, SubscriptionRepository $subscriptions): Response
    {
        $form = $this->createForm(RecipientType::class, $recipient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->events->record(null, EventType::ConfigChange, \sprintf('Zmieniono odbiorcę %s', $recipient->getAddress()));
            $this->entityManager->flush();
            $this->addFlash('success', 'Zapisano odbiorcę.');

            return $this->redirectToRoute('app_recipient_edit', ['id' => $recipient->getId()]);
        }

        $subscriptionForm = $this->createForm(SubscriptionType::class, new Subscription($recipient, null), [
            'action' => $this->generateUrl('app_subscription_create', ['id' => $recipient->getId()]),
        ]);

        return $this->render('admin/recipient/edit.html.twig', [
            'recipient' => $recipient,
            'form' => $form,
            'subscription_form' => $subscriptionForm,
            'subscriptions' => $subscriptions->findBy(['recipient' => $recipient], ['id' => 'ASC']),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_recipient_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Recipient $recipient, Request $request): Response
    {
        $this->assertCsrf('recipient'.$recipient->getId(), $request);
        $this->events->record(null, EventType::ConfigChange, \sprintf('Usunięto odbiorcę %s', $recipient->getAddress()));
        $this->entityManager->remove($recipient);
        $this->entityManager->flush();
        $this->addFlash('success', 'Usunięto odbiorcę.');

        return $this->redirectToRoute('app_recipient_index');
    }

    #[Route('/{id}/test', name: 'app_recipient_test', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function test(Recipient $recipient, Request $request, TestNotificationSender $sender): Response
    {
        $this->assertCsrf('recipient'.$recipient->getId(), $request);

        try {
            $sender->send($recipient->getChannel(), $recipient->getAddress());
            $this->addFlash('success', \sprintf('Wysłano testowy e-mail do %s.', $recipient->getAddress()));
        } catch (NotificationFailedException $e) {
            $this->addFlash('error', 'Wysyłka nie powiodła się: '.$e->getMessage());
        }

        return $this->redirectToRoute('app_recipient_edit', ['id' => $recipient->getId()]);
    }

    #[Route('/{id}/subscriptions', name: 'app_subscription_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addSubscription(Recipient $recipient, Request $request): Response
    {
        $subscription = new Subscription($recipient, null);
        $form = $this->createForm(SubscriptionType::class, $subscription);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($subscription);
            $this->entityManager->flush();
            $this->addFlash('success', 'Dodano subskrypcję.');
        } else {
            $this->addFlash('error', 'Nie udało się dodać subskrypcji.');
        }

        return $this->redirectToRoute('app_recipient_edit', ['id' => $recipient->getId()]);
    }

    #[Route('/subscriptions/{id}/delete', name: 'app_subscription_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteSubscription(Subscription $subscription, Request $request): Response
    {
        $recipientId = $subscription->getRecipient()->getId();
        $this->assertCsrf('recipient'.$recipientId, $request);
        $this->entityManager->remove($subscription);
        $this->entityManager->flush();
        $this->addFlash('success', 'Usunięto subskrypcję.');

        return $this->redirectToRoute('app_recipient_edit', ['id' => $recipientId]);
    }

    private function assertCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Nieprawidłowy token CSRF.');
        }
    }
}
