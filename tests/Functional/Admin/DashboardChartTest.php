<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use App\Token\IssuedToken;

final class DashboardChartTest extends PanelTestCase
{
    public function testChooseResetAndRejectDashboardChart(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $this->heartbeats($token, ['schema' => 1, 'cpu_temp_c' => 45, 'load' => [0.5], 'disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 50], ['mount' => '/srv/backup', 'size_b' => 100, 'used_b' => 80]]]);
        $device = $this->reload($device);
        $device->setDiskLabels(['/srv/backup' => 'Backup']);
        $this->em()->flush();

        // Domyślnie: temperatura CPU
        $this->client->request('GET', '/dashboard/cards');
        self::assertSelectorTextContains('.sparkline-label', 'Temperatura CPU, 24 h');

        $crawler = $this->client->request('GET', '/devices/'.$device->getId());
        self::assertSelectorTextContains('#dashboard-chart option[value=""]', 'Automatycznie (Temperatura CPU)');
        self::assertSelectorTextContains('#dashboard-chart option[value="disk./srv/backup.used_pct"]', 'Dysk Backup, /srv/backup');
        $form = $crawler->filter('.dashboard-chart-form')->form(['metric' => 'disk./srv/backup.used_pct']);
        $this->client->submit($form);
        self::assertResponseRedirects('/devices/'.$device->getId().'#charts');
        self::assertSame('disk./srv/backup.used_pct', $this->reload($device)->getDashboardChart());

        $this->client->request('GET', '/dashboard/cards');
        self::assertSelectorTextContains('.sparkline-label', 'Dysk Backup, /srv/backup, 24 h');
        self::assertSelectorExists('.sparkline path');

        // Metryka, której urządzenie nie raportuje
        $csrf = (string) $crawler->filter('.dashboard-chart-form input[name=_token]')->attr('value');
        $this->client->request('POST', '/devices/'.$device->getId().'/dashboard-chart', ['_token' => $csrf, 'metric' => 'disk./etc.used_pct']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('disk./srv/backup.used_pct', $this->reload($device)->getDashboardChart());

        // Powrót do automatycznego
        $this->client->request('POST', '/devices/'.$device->getId().'/dashboard-chart', ['_token' => $csrf, 'metric' => '']);
        self::assertNull($this->reload($device)->getDashboardChart());
    }

    public function testChosenMetricNoLongerReportedFallsBackToAutomatic(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $this->heartbeats($token, ['schema' => 1, 'load' => [0.5]]);
        $device = $this->reload($device);
        $device->setDashboardChart('cpu.temp_c');
        $this->em()->flush();

        $this->client->request('GET', '/dashboard/cards');
        self::assertSelectorTextContains('.sparkline-label', 'Load (1 min), 24 h');

        $this->client->request('GET', '/devices/'.$device->getId());
        self::assertSelectorTextContains('.dashboard-chart-form', 'nie jest już raportowana');
    }

    public function testRequiresCsrf(): void
    {
        [$device] = $this->createDeviceWithToken();

        $this->client->request('POST', '/devices/'.$device->getId().'/dashboard-chart', ['_token' => 'zły', 'metric' => '']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Dwa heartbeaty (miniwykres potrzebuje co najmniej dwóch punktów).
     *
     * @param array<string, mixed> $payload
     */
    private function heartbeats(IssuedToken $token, array $payload): void
    {
        foreach (['2026-11-20 11:58:00', '2026-11-20 11:59:00'] as $time) {
            self::mockTime($time);
            $fresh = $this->em()->find(DeviceToken::class, $token->token->getId());
            self::assertNotNull($fresh);
            $parsed = self::getContainer()->get(HeartbeatParser::class)->parse($payload, new \DateTimeImmutable($time));
            self::getContainer()->get(HeartbeatIngestor::class)->ingest($fresh, $parsed, null);
        }
        self::mockTime('2026-11-20 12:00:00');
    }

    private function reload(Device $device): Device
    {
        $this->em()->clear();
        $fresh = $this->em()->find(Device::class, $device->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
