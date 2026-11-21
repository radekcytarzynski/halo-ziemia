<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\OutboxMessage;
use App\Enum\NotificationChannel;
use App\Settings\Settings;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Wysyłka synchroniczna przez Symfony Mailer (bez Messengera – hosting nie ma workerów).
 */
final readonly class EmailNotifier implements NotifierInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private Settings $settings,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function send(OutboxMessage $message): void
    {
        $email = (new Email())
            ->from(new Address($this->settings->mailFrom(), $this->settings->mailFromName()))
            ->to($message->getAddress())
            ->subject($message->getSubject())
            ->text($message->getBodyText());

        if (null !== $message->getBodyHtml()) {
            $email->html($message->getBodyHtml());
        }
        $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface|\InvalidArgumentException|\LogicException $e) {
            throw new NotificationFailedException($e->getMessage(), 0, $e);
        }
    }
}
