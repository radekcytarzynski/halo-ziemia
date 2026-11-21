<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\OutboxMessage;
use App\Enum\NotificationChannel;
use App\Enum\OutboxStatus;
use App\Settings\Settings;

final class SettingsAndLogTest extends PanelTestCase
{
    public function testSaveSettings(): void
    {
        $crawler = $this->client->request('GET', '/settings');
        self::assertSelectorTextContains('#cron-lines', 'app:tick');

        $this->client->submit($crawler->selectButton('Zapisz')->form([
            'settings[missed_intervals_threshold]' => '5',
            'settings[reminder_interval_hours]' => '0',
            'settings[mail_from]' => 'monitor@example.test',
            'settings[retention_raw_days]' => '7',
            'settings[retention_rollup_days]' => '365',
        ]));
        self::assertResponseRedirects('/settings');

        $settings = self::getContainer()->get(Settings::class);
        $settings->reset();
        self::assertSame(5, $settings->missedIntervalsThreshold());
        self::assertSame(0, $settings->reminderIntervalSeconds());
        self::assertSame('monitor@example.test', $settings->mailFrom());
        self::assertSame(7, $settings->retentionRawDays());
    }

    public function testInvalidSettingsRejected(): void
    {
        $crawler = $this->client->request('GET', '/settings');
        $this->client->submit($crawler->selectButton('Zapisz')->form(['settings[missed_intervals_threshold]' => '0']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testEventLogFilters(): void
    {
        $this->createDeviceWithToken('Alfa');
        [$beta] = $this->createDeviceWithToken('Beta');

        $crawler = $this->client->request('GET', '/log');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Alfa', $crawler->filter('.timeline')->text());

        $crawler = $this->client->request('GET', '/log?device='.$beta->getId().'&type=config_change');
        $text = $crawler->filter('.timeline')->text();
        self::assertStringContainsString('Beta', $text);
        self::assertStringNotContainsString('„Alfa”', $text);

        $this->client->request('GET', '/log?severity=crit');
        self::assertSelectorTextContains('main', 'Brak zdarzeń');
    }

    public function testNotificationsLogAndRetry(): void
    {
        $message = new OutboxMessage(null, null, NotificationChannel::Email, 'a@example.test', '[halo-ziemia] DOWN: NAS', 'x', null, new \DateTimeImmutable());
        for ($i = 0; $i < 48; ++$i) {
            $message->markAttemptFailed('SMTP timeout', new \DateTimeImmutable(), $i >= 47);
        }
        $this->em()->persist($message);
        $this->em()->flush();

        $this->client->request('GET', '/log/notifications?status=failed');
        self::assertSelectorTextContains('table', 'SMTP timeout');

        $this->clickPostButton('/log/notifications?status=failed', 'Ponów');
        self::assertResponseRedirects('/log/notifications');
        $this->em()->clear();
        self::assertSame(OutboxStatus::Pending, $this->em()->find(OutboxMessage::class, $message->getId())?->getStatus());
    }

    public function testAllPagesRequireLogin(): void
    {
        $this->client->restart();
        foreach (['/', '/dashboard/cards', '/devices', '/recipients', '/settings', '/log', '/log/notifications'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', message: $url);
        }
    }
}
