<?php

declare(strict_types=1);

namespace App\Tests\Functional\Alerting;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Enum\EventType;
use App\Enum\NotificationChannel;
use App\Enum\OutboxStatus;
use App\Notification\NotificationFailedException;
use App\Notification\NotifierInterface;
use App\Notification\NotifierRegistry;
use App\Notification\OutboxProcessor;
use App\Notification\RetryPolicy;
use App\Repository\OutboxMessageRepository;
use App\Timeline\EventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboxProcessorTest extends KernelTestCase
{
    private const string NOW = '2026-11-20 12:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testSendsDueMessagesWithEmailChannel(): void
    {
        $message = $this->enqueue();

        $result = self::getContainer()->get(OutboxProcessor::class)->process(new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $result->sent);
        self::assertSame(OutboxStatus::Sent, $message->getStatus());
        self::assertSame(1, $message->getAttempts());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'admin@example.test');
        self::assertEmailHeaderSame($email, 'Subject', '[halo-ziemia] DOWN: NAS');
        self::assertEmailTextBodyContains($email, 'Treść');
        self::assertEmailAddressContains($email, 'From', 'halo-ziemia@example.com');
    }

    public function testFailureIsRetriedWithBackoffAndNotLost(): void
    {
        $message = $this->enqueue();
        $processor = $this->processorWith($this->failingNotifier());

        $result = $processor->process(new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $result->retrying);
        self::assertSame(OutboxStatus::Pending, $message->getStatus());
        self::assertSame(1, $message->getAttempts());
        self::assertSame('SMTP niedostępny', $message->getLastError());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 12:01:00'), $message->getNextAttemptAt());

        $result = $processor->process(new \DateTimeImmutable('2026-11-20 12:00:30'));
        self::assertSame(0, $result->retrying, 'przed terminem kolejnej próby nic nie robimy');

        $result = self::getContainer()->get(OutboxProcessor::class)->process(new \DateTimeImmutable('2026-11-20 12:01:00'));
        self::assertSame(1, $result->sent, 'po naprawie SMTP wiadomość wychodzi');
        self::assertSame(OutboxStatus::Sent, $message->getStatus());
    }

    public function testGivesUpAfterMaxAttemptsAndRecordsEvent(): void
    {
        $message = $this->enqueue();
        $processor = $this->processorWith($this->failingNotifier());
        $now = new \DateTimeImmutable(self::NOW);

        for ($i = 0; $i < RetryPolicy::MAX_ATTEMPTS; ++$i) {
            $processor->process($now);
            $now = $message->getNextAttemptAt();
        }

        self::assertSame(OutboxStatus::Failed, $message->getStatus());
        self::assertSame(RetryPolicy::MAX_ATTEMPTS, $message->getAttempts());
        $events = $this->em()->getRepository(Event::class)->findBy(['type' => EventType::NotificationFailed]);
        self::assertCount(1, $events);
    }

    private function enqueue(): OutboxMessage
    {
        $message = new OutboxMessage(null, null, NotificationChannel::Email, 'admin@example.test', '[halo-ziemia] DOWN: NAS', 'Treść', '<p>Treść</p>', new \DateTimeImmutable(self::NOW));
        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    private function processorWith(NotifierInterface $notifier): OutboxProcessor
    {
        $container = self::getContainer();

        return new OutboxProcessor(
            $container->get(OutboxMessageRepository::class),
            new NotifierRegistry([$notifier]),
            new RetryPolicy(),
            $container->get(EventRecorder::class),
            $this->em(),
            new NullLogger(),
        );
    }

    private function failingNotifier(): NotifierInterface
    {
        return new class implements NotifierInterface {
            public function channel(): NotificationChannel
            {
                return NotificationChannel::Email;
            }

            public function send(OutboxMessage $message): void
            {
                throw new NotificationFailedException('SMTP niedostępny');
            }
        };
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
