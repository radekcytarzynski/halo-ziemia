<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use App\Settings\Settings;

final class DashboardTest extends PanelTestCase
{
    public function testEmptyDashboardWithCronBanner(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state h2', 'Brak urządzeń');
        self::assertSelectorTextContains('.banner-crit', 'Cron nie działa');
    }

    public function testCronBannerHiddenAfterRecentTickAndShownWhenStale(): void
    {
        self::getContainer()->get(Settings::class)->markTick(new \DateTimeImmutable('2026-11-20 11:58:30'));
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.banner-crit');

        self::mockTime('2026-11-20 12:02:00');
        $this->client->request('GET', '/dashboard/cards');
        self::assertSelectorTextContains('.banner-crit', 'Cron nie działa');
    }

    public function testDeviceCardShowsStatusIndicatorsAndProblems(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $parser = self::getContainer()->get(HeartbeatParser::class);
        $ingestor = self::getContainer()->get(HeartbeatIngestor::class);
        $payload = ['schema' => 1, 'cpu_temp_c' => 52.5, 'load' => [0.5], 'disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 97], ['mount' => '/boot', 'size_b' => 100, 'used_b' => 10]]];
        foreach (['2026-11-20 11:58:00', '2026-11-20 11:59:00'] as $time) {
            self::mockTime($time);
            $ingestor->ingest($token->token, $parser->parse($payload, new \DateTimeImmutable($time)), null);
        }
        self::mockTime('2026-11-20 12:00:00');

        $this->client->request('GET', '/dashboard/cards');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextContains('.device-card h2', 'NAS Debian');
        self::assertSelectorTextContains('.device-card .status', 'Krytyczny');
        self::assertSelectorTextContains('.device-card-seen', '1 min temu');
        self::assertSelectorTextContains('.indicator-crit dt', 'Dysk /');
        self::assertSelectorTextContains('.indicator-crit dd', '97');
        self::assertSelectorTextContains('.problems', 'CRIT: Zajętość dysku (/)');
        self::assertSelectorExists('.sparkline path');
        self::assertSelectorNotExists('nav.main-nav', 'fragment bez layoutu');
        self::assertSelectorExists('a.device-card[href="/devices/'.$device->getId().'"]');
    }
}
