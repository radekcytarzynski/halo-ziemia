<?php

declare(strict_types=1);

namespace App\Tests\Functional\Alerting;

use App\Alerting\AlertEngine;
use App\Entity\AlertState;
use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Incident;
use App\Entity\OutboxMessage;
use App\Entity\Recipient;
use App\Entity\Subscription;
use App\Enum\DeviceStatus;
use App\Enum\EventType;
use App\Enum\NotificationChannel;
use App\Enum\Severity;
use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use App\Settings\Settings;
use App\Tests\Functional\DeviceFixtures;
use App\Token\IssuedToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Scenariusze end-to-end silnika: heartbeat → reguły → stan → outbox (bez HTTP).
 */
final class AlertScenarioTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DeviceFixtures;

    private Device $device;
    private IssuedToken $token;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-20 12:00:00');
        Clock::set($this->clock); // przywracany po teście przez ClockSensitiveTrait
        self::bootKernel();
        [$this->device, $this->token] = $this->createDeviceWithToken();
    }

    public function testFirstHeartbeatMakesDeviceOkWithoutNotifications(): void
    {
        $this->subscribe('admin@example.test', Severity::Warn);
        self::assertSame(DeviceStatus::Pending, $this->device->getStatus());

        $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 40]]]);

        self::assertSame(DeviceStatus::Ok, $this->device->getStatus());
        self::assertSame([], $this->outbox());
    }

    public function testDiskThresholdWithAntiFlappingNotifiesOnce(): void
    {
        $this->subscribe('admin@example.test', Severity::Warn);
        $full = ['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 96]]];

        $this->heartbeat($full);
        self::assertSame(DeviceStatus::Ok, $this->device->getStatus(), 'reguła dysku wymaga 2 kolejnych próbek');

        $this->advance('+1 minute');
        $this->heartbeat($full);
        self::assertSame(DeviceStatus::Crit, $this->device->getStatus());

        $outbox = $this->outbox();
        self::assertCount(1, $outbox);
        self::assertSame('[halo-ziemia] CRIT: NAS Debian – Zajętość dysku (/)', $outbox[0]->getSubject());
        self::assertSame('admin@example.test', $outbox[0]->getAddress());
        self::assertStringContainsString('96 % (WARN > 85 %, CRIT > 95 %)', str_replace("\u{00A0}", ' ', $outbox[0]->getBodyText()));
        self::assertStringContainsString('http://localhost/devices/'.$this->device->getId(), $outbox[0]->getBodyText());
        self::assertNotNull($outbox[0]->getBodyHtml());

        $this->advance('+1 minute');
        $this->heartbeat($full);
        self::assertCount(1, $this->outbox(), 'deduplikacja: bez zmiany stanu nie ma kolejnego maila');

        $incidents = $this->em()->getRepository(Incident::class)->findBy(['device' => $this->device]);
        self::assertCount(1, $incidents);
        self::assertTrue($incidents[0]->isOpen());
        self::assertSame(Severity::Crit, $incidents[0]->getMaxSeverity());
    }

    public function testHysteresisPreventsRecoveryJustBelowThreshold(): void
    {
        $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 90]]]);
        $this->advance('+1 minute');
        $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 90]]]);
        self::assertSame(DeviceStatus::Warn, $this->device->getStatus());

        for ($i = 0; $i < 3; ++$i) {
            $this->advance('+1 minute');
            $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 84]]]);
        }
        self::assertSame(DeviceStatus::Warn, $this->device->getStatus(), '84 % jest w pasie histerezy (85 − 2)');

        $this->advance('+1 minute');
        $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 80]]]);
        $this->advance('+1 minute');
        $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 80]]]);
        self::assertSame(DeviceStatus::Ok, $this->device->getStatus());
    }

    public function testMissingHeartbeatsMakeDeviceDownAndRecoveryIsNotified(): void
    {
        $this->subscribe('admin@example.test', Severity::Crit);
        $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 40]], 'cpu_temp_c' => 45.5]);

        $this->advance('+3 minutes');
        $this->tick();
        self::assertSame(DeviceStatus::Ok, $this->device->getStatus(), '180 s < 210 s');

        $this->advance('+31 seconds');
        $this->tick();
        self::assertSame(DeviceStatus::Down, $this->device->getStatus());

        $outbox = $this->outbox();
        self::assertCount(1, $outbox);
        self::assertSame('[halo-ziemia] DOWN: NAS Debian – Kontakt z agentem', $outbox[0]->getSubject());
        $text = str_replace("\u{00A0}", ' ', $outbox[0]->getBodyText());
        self::assertStringContainsString('Ostatni kontakt: 2026-11-20 13:00', $text, 'czas w Europe/Warsaw');
        self::assertStringContainsString('Dysk /: 40 %', $text);
        self::assertStringContainsString('Temperatura CPU: 45,5 °C', $text);

        $this->advance('+5 minutes');
        $this->tick();
        self::assertCount(1, $this->outbox(), 'bez duplikatów przy kolejnych tickach');

        $this->heartbeat([]);
        self::assertSame(DeviceStatus::Ok, $this->device->getStatus());
        $outbox = $this->outbox();
        self::assertCount(2, $outbox);
        self::assertSame('[halo-ziemia] ODZYSKANO: NAS Debian – Kontakt z agentem', $outbox[1]->getSubject());
    }

    public function testPingAlsoCountsAsContact(): void
    {
        $this->heartbeat([]);
        $this->advance('+10 minutes');
        $this->tick();
        self::assertSame(DeviceStatus::Down, $this->device->getStatus());

        self::getContainer()->get(HeartbeatIngestor::class)->ingest($this->token->token, null, '127.0.0.1');

        self::assertSame(DeviceStatus::Ok, $this->device->getStatus());
    }

    public function testMaintenanceModeSuppressesNotificationsAndCatchesUpAfterwards(): void
    {
        $this->subscribe('admin@example.test', Severity::Warn);
        $this->heartbeat([]);
        $this->device->setMutedUntil(new \DateTimeImmutable('2026-11-20 12:30:00'));

        $this->advance('+10 minutes');
        $this->tick();
        self::assertSame(DeviceStatus::Down, $this->device->getStatus(), 'status pokazuje prawdę również w trybie serwisowym');
        self::assertSame([], $this->outbox());

        $events = $this->em()->getRepository(Event::class)->findBy(['device' => $this->device, 'type' => EventType::StateChange]);
        self::assertCount(1, $events, 'zdarzenie zapisane mimo wyciszenia');
        self::assertStringContainsString('tryb serwisowy', $events[0]->getMessage());

        $this->advance('+21 minutes');
        $this->tick();
        $outbox = $this->outbox();
        self::assertCount(1, $outbox, 'po wyciszeniu jedno powiadomienie o bieżącym stanie');
        self::assertStringStartsWith('[halo-ziemia] DOWN:', $outbox[0]->getSubject());
    }

    public function testRecoveryDuringMaintenanceIsSilentWhenProblemWasNeverNotified(): void
    {
        $this->subscribe('admin@example.test', Severity::Warn);
        $this->heartbeat([]);
        $this->device->setMutedUntil(new \DateTimeImmutable('2026-11-20 12:30:00'));
        $this->advance('+10 minutes');
        $this->tick();
        $this->heartbeat([]);

        $this->advance('+21 minutes');
        $this->heartbeat([]);
        $this->tick();

        self::assertFalse($this->device->isMutedAt($this->clock->now()));
        self::assertSame(DeviceStatus::Ok, $this->device->getStatus());
        self::assertSame([], $this->outbox());
    }

    public function testRemindersAreSentAtConfiguredInterval(): void
    {
        $this->subscribe('admin@example.test', Severity::Warn);
        self::getContainer()->get(Settings::class)->set(Settings::REMINDER_INTERVAL_SECONDS, 3600);
        $this->heartbeat([]);
        $this->advance('+5 minutes');
        $this->tick();
        self::assertCount(1, $this->outbox());

        $this->advance('+59 minutes');
        $this->tick();
        self::assertCount(1, $this->outbox());

        $this->advance('+1 minute');
        $this->tick();
        $outbox = $this->outbox();
        self::assertCount(2, $outbox);
        self::assertSame('[halo-ziemia] PRZYPOMNIENIE DOWN: NAS Debian – Kontakt z agentem', $outbox[1]->getSubject());

        $state = $this->em()->getRepository(AlertState::class)->findOneBy(['device' => $this->device, 'subject' => AlertState::SUBJECT_HEARTBEAT]);
        self::assertSame(1, $state?->getReminderCount());
    }

    public function testSeveritySubscriptionFiltersRecipients(): void
    {
        $this->subscribe('wszystko@example.test', Severity::Warn);
        $this->subscribe('tylko-krytyczne@example.test', Severity::Crit);

        $this->heartbeat(['mem' => ['total_kb' => 1000, 'available_kb' => 80]]);
        $this->advance('+1 minute');
        $this->heartbeat(['mem' => ['total_kb' => 1000, 'available_kb' => 80]]);
        $this->advance('+1 minute');
        $this->heartbeat(['mem' => ['total_kb' => 1000, 'available_kb' => 80]]);

        self::assertSame(DeviceStatus::Warn, $this->device->getStatus());
        self::assertSame(['wszystko@example.test'], array_map(static fn (OutboxMessage $m): string => $m->getAddress(), $this->outbox()));
    }

    public function testFailingCheckAndBackupAgeRules(): void
    {
        $this->subscribe('admin@example.test', Severity::Warn);

        $this->heartbeat(['checks' => [['name' => 'backup', 'status' => 'fail', 'message' => 'rsync: kod 23', 'last_success_age_s' => 200000]]]);

        self::assertSame(DeviceStatus::Crit, $this->device->getStatus());
        $outbox = $this->outbox();
        self::assertCount(1, $outbox, 'obie zmiany w jednym mailu');
        self::assertSame('[halo-ziemia] CRIT: NAS Debian', $outbox[0]->getSubject());
        self::assertStringContainsString('Check (backup) – rsync: kod 23', $outbox[0]->getBodyText());
        self::assertStringContainsString('Wiek backupu', $outbox[0]->getBodyText());
    }

    public function testDisabledDeviceIsPausedAndNotEvaluated(): void
    {
        $this->subscribe('admin@example.test', Severity::Warn);
        $this->heartbeat([]);
        $this->device->setEnabled(false);

        $this->advance('+1 hour');
        $this->tick();

        self::assertSame(DeviceStatus::Paused, $this->device->getStatus());
        self::assertSame([], $this->outbox());
    }

    public function testStaleMetricStateIsClosedAfter24Hours(): void
    {
        $full = ['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 99], ['mount' => '/mnt/usb', 'size_b' => 100, 'used_b' => 99]]];
        $this->heartbeat($full);
        $this->advance('+1 minute');
        $this->heartbeat($full);
        self::assertSame(DeviceStatus::Crit, $this->device->getStatus());

        // USB odłączony, "/" wraca do normy
        for ($i = 0; $i < 2; ++$i) {
            $this->advance('+1 minute');
            $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 10]]]);
        }
        self::assertSame(DeviceStatus::Crit, $this->device->getStatus(), '/mnt/usb nadal w alarmie');

        $this->advance('+1 day');
        $this->heartbeat(['disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 10]]]);
        $this->tick();

        self::assertSame(DeviceStatus::Ok, $this->device->getStatus());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function heartbeat(array $payload): void
    {
        $parsed = self::getContainer()->get(HeartbeatParser::class)->parse(['schema' => 1] + $payload, $this->clock->now());
        self::getContainer()->get(HeartbeatIngestor::class)->ingest($this->token->token, $parsed, '127.0.0.1');
    }

    private function tick(): void
    {
        self::getContainer()->get(AlertEngine::class)->tick($this->clock->now());
    }

    private function advance(string $modifier): void
    {
        $this->clock->modify($modifier);
    }

    private function subscribe(string $email, Severity $minSeverity): void
    {
        $recipient = new Recipient($email, NotificationChannel::Email, $email, new \DateTimeImmutable());
        $subscription = new Subscription($recipient, null);
        $subscription->setMinSeverity($minSeverity);
        $this->em()->persist($recipient);
        $this->em()->persist($subscription);
        $this->em()->flush();
    }

    /**
     * @return list<OutboxMessage>
     */
    private function outbox(): array
    {
        return $this->em()->getRepository(OutboxMessage::class)->findBy([], ['id' => 'ASC']);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
