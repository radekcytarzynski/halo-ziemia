<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Enum\DeviceStatus;
use App\Settings\Settings;
use App\Tests\Functional\DeviceFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

final class TickCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DeviceFixtures;

    private CommandTester $tester;

    protected function setUp(): void
    {
        self::mockTime('2026-11-20 12:00:00');
        $this->tester = new CommandTester((new Application(self::bootKernel()))->find('app:tick'));
    }

    public function testTickEvaluatesDevicesAndRecordsLastTick(): void
    {
        [$device] = $this->createDeviceWithToken();
        $device->setLastSeenAt(new \DateTimeImmutable('2026-11-20 11:00:00'));

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        self::assertStringContainsString('Urządzenia: 1 (DOWN: 1)', $this->tester->getDisplay());
        self::assertStringContainsString('watchdog: wyłączony', $this->tester->getDisplay());
        self::assertSame(DeviceStatus::Down, $device->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 12:00:00'), self::getContainer()->get(Settings::class)->lastTickAt());
    }

    public function testSkipsWhenPreviousTickStillRuns(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock('app-tick');
        self::assertTrue($lock->acquire());

        try {
            self::assertSame(Command::SUCCESS, $this->tester->execute([]));
            self::assertStringContainsString('nadal działa', $this->tester->getDisplay());
            self::assertNull(self::getContainer()->get(Settings::class)->lastTickAt());
        } finally {
            $lock->release();
        }
    }

    public function testNotifyTestCommandSendsEmail(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:notify:test'));

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'admin@example.test']));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'Subject', '[halo-ziemia] Test powiadomień');
    }

    public function testStatusCommand(): void
    {
        $this->createDeviceWithToken();
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:status'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('app:tick jeszcze się nie wykonał', $display);
        self::assertStringContainsString('Urządzenia: 1 × Oczekuje', $display);

        $this->tester->execute([]);
        $tester->execute([]);
        self::assertStringContainsString('Cron: OK', $tester->getDisplay());
    }
}
