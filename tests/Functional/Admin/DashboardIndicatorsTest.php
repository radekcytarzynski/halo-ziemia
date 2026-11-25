<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use App\Token\IssuedToken;

final class DashboardIndicatorsTest extends PanelTestCase
{
    private const array PAYLOAD = [
        'schema' => 1,
        'cpu_temp_c' => 45,
        'cpu' => ['used_pct' => 12.5, 'iowait_pct' => 0.5],
        'load' => [0.5, 0.4, 0.3],
        'mem' => ['total_kb' => 1000, 'available_kb' => 500, 'swap_total_kb' => 100, 'swap_used_kb' => 25],
        'disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 50], ['mount' => '/srv/backup', 'size_b' => 100, 'used_b' => 80]],
    ];

    public function testChooseIndicatorsLimitAndReset(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $this->heartbeat($token);
        $device = $this->reload($device);
        $device->setDiskLabels(['/srv/backup' => 'Backup']);
        $this->em()->flush();
        $url = '/devices/'.$device->getId();

        // Automatycznie: najbardziej zajęty dysk, CPU, temperatura, load
        self::assertSame(['Backup', 'CPU', 'Temp. CPU', 'Load'], $this->cardLabels());

        $crawler = $this->client->request('GET', $url);
        $checked = $crawler->filter('#dashboard-indicators input[type=checkbox]:checked')->extract(['value']);
        self::assertSame(['disk./srv/backup.used_pct', 'cpu.used_pct', 'cpu.temp_c', 'load.1'], $checked, 'kolejność listy = kolejność na karcie');
        self::assertSame(['disk./.used_pct', 'disk./srv/backup.used_pct', 'cpu.used_pct', 'cpu.temp_c', 'cpu.iowait_pct', 'load.1'], \array_slice($crawler->filter('#dashboard-indicators input[type=checkbox]')->extract(['value']), 0, 6));
        self::assertSelectorNotExists('#dashboard-indicators button[name=reset]');
        $csrf = (string) $crawler->filter('#dashboard-indicators input[name=_token]')->attr('value');

        // Własny wybór (kolejność z formularza; nieznana metryka pominięta)
        $this->client->request('POST', $url.'/dashboard-indicators', ['_token' => $csrf, 'indicators' => ['cpu.used_pct', 'disk./.used_pct', 'mem.swap_used_pct', 'disk./etc.used_pct']]);
        self::assertResponseRedirects($url.'#dashboard-indicators');
        self::assertSame(['cpu.used_pct', 'disk./.used_pct', 'mem.swap_used_pct'], $this->reload($device)->getDashboardIndicators());
        self::assertSame(['CPU', 'Dysk /', 'Swap'], $this->cardLabels());

        $this->client->request('GET', $url);
        self::assertSelectorExists('#dashboard-indicators button[name=reset]');
        self::assertSelectorTextContains('#disks', 'wybrane ręcznie');

        // Za dużo – bez zmian
        $all = $crawler->filter('#dashboard-indicators input[type=checkbox]')->extract(['value']);
        self::assertGreaterThan(8, \count($all));
        $this->client->request('POST', $url.'/dashboard-indicators', ['_token' => $csrf, 'indicators' => \array_slice($all, 0, 9)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'najwyżej 8');
        self::assertSame(['cpu.used_pct', 'disk./.used_pct', 'mem.swap_used_pct'], $this->reload($device)->getDashboardIndicators());

        // Powrót do automatycznych
        $this->client->request('POST', $url.'/dashboard-indicators', ['_token' => $csrf, 'reset' => '1', 'indicators' => ['cpu.used_pct']]);
        self::assertSame([], $this->reload($device)->getDashboardIndicators());
        self::assertSame(['Backup', 'CPU', 'Temp. CPU', 'Load'], $this->cardLabels());
    }

    public function testChosenMetricsNoLongerReportedFallBackToAutomatic(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $this->heartbeat($token);
        $device = $this->reload($device);
        $device->setDashboardIndicators(['smart.sda.temp_c']);
        $this->em()->flush();

        self::assertSame(['Dysk /srv/backup', 'CPU', 'Temp. CPU', 'Load'], $this->cardLabels());

        $this->client->request('GET', '/devices/'.$device->getId());
        self::assertSelectorTextContains('#dashboard-indicators', 'nie są już raportowane');
    }

    public function testRequiresCsrf(): void
    {
        [$device] = $this->createDeviceWithToken();

        $this->client->request('POST', '/devices/'.$device->getId().'/dashboard-indicators', ['_token' => 'zły', 'reset' => '1']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<string>
     */
    private function cardLabels(): array
    {
        $crawler = $this->client->request('GET', '/dashboard/cards');

        return $crawler->filter('.indicator dt')->each(static fn ($dt): string => trim($dt->text()));
    }

    private function heartbeat(IssuedToken $token): void
    {
        $fresh = $this->em()->find(DeviceToken::class, $token->token->getId());
        self::assertNotNull($fresh);
        $parsed = self::getContainer()->get(HeartbeatParser::class)->parse(self::PAYLOAD, new \DateTimeImmutable());
        self::getContainer()->get(HeartbeatIngestor::class)->ingest($fresh, $parsed, null);
    }

    private function reload(Device $device): Device
    {
        $this->em()->clear();
        $fresh = $this->em()->find(Device::class, $device->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
