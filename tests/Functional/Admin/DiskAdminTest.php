<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use App\Token\IssuedToken;

final class DiskAdminTest extends PanelTestCase
{
    private const array PAYLOAD = [
        'schema' => 1,
        'ip' => ['fd00::1', '192.168.1.10', '172.17.0.1'],
        'disks' => [
            ['mount' => '/', 'device' => '/dev/sda1', 'size_b' => 100, 'used_b' => 50],
            ['mount' => '/mnt/backup', 'device' => '/dev/sdb1', 'size_b' => 100, 'used_b' => 97],
            ['mount' => '/mnt/data', 'device' => '/dev/sdc1', 'size_b' => 100, 'used_b' => 20],
        ],
        'smart' => [['device' => '/dev/sda', 'passed' => true, 'temp_c' => 35]],
    ];

    public function testNamesDashboardDiskAlertTitlesAndIps(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $this->heartbeat($token, '2026-11-20 11:58:00');
        $this->heartbeat($token, '2026-11-20 11:59:00');
        self::mockTime('2026-11-20 12:00:00');

        // Strona urządzenia: lista dysków, adresy IP
        $crawler = $this->client->request('GET', '/devices/'.$device->getId());
        self::assertResponseIsSuccessful();
        $diskIds = $crawler->filter('#disks input[type=hidden][name$="[id]"]')->each(static fn ($n): string => (string) $n->attr('value'));
        self::assertSame(['/', '/mnt/backup', '/mnt/data', 'sda'], $diskIds);
        self::assertSelectorTextContains('#overview .ip-list', '192.168.1.10');
        self::assertSelectorTextContains('#overview .ip-list', 'fd00::1');
        self::assertSelectorTextContains('#overview', '203.0.113.7');

        $csrf = (string) $crawler->filter('#disks input[name=_token]')->attr('value');
        $this->client->request('POST', '/devices/'.$device->getId().'/disks', [
            '_token' => $csrf,
            'disk' => [
                ['id' => '/mnt/backup', 'label' => ' Backup  NAS '],
                ['id' => '/mnt/data', 'label' => 'Dane'],
                ['id' => 'sda', 'label' => 'System'],
                ['id' => '/etc/obcy', 'label' => 'spoza listy – pominięty'],
                ['id' => '/', 'label' => ''],
            ],
            'dashboard_disk' => '/mnt/data',
        ]);
        self::assertResponseRedirects('/devices/'.$device->getId().'#disks');

        $device = $this->reload($device);
        self::assertSame(['/mnt/backup' => 'Backup NAS', '/mnt/data' => 'Dane', 'sda' => 'System'], $device->getDiskLabels());
        self::assertSame('/mnt/data', $device->getDashboardDisk());

        // Dashboard: wybrany dysk z nazwą (nie najbardziej zajęty), główny adres IPv4
        $this->client->request('GET', '/dashboard/cards');
        self::assertSelectorTextContains('.indicator dt', 'Dane');
        self::assertSelectorTextContains('.indicator dd', '20');
        self::assertSelectorTextContains('.device-card-ip', '192.168.1.10');
        self::assertSelectorTextContains('.device-card-ip', '+2');

        // Tytuł alarmu dostaje nazwę przy następnym heartbeacie
        $this->heartbeat($token, '2026-11-20 12:00:00');
        $this->client->request('GET', '/dashboard/cards');
        self::assertSelectorTextContains('.problems', 'CRIT: Zajętość dysku (Backup NAS, /mnt/backup)');

        // Wykresy podpisane nazwą
        $this->client->request('GET', '/devices/'.$device->getId());
        self::assertSelectorTextContains('#charts', 'Dysk Backup NAS, /mnt/backup');
    }

    public function testUnknownDashboardDiskFallsBackToFullestAndEmptyLabelRemoves(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $this->heartbeat($token, '2026-11-20 12:00:00');
        $device = $this->reload($device);
        $device->setDiskLabels(['/mnt/data' => 'Dane']);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/devices/'.$device->getId());
        $form = $crawler->filter('#disks form')->form();
        foreach ($form->all() as $name => $field) {
            if (str_ends_with($name, '[label]')) {
                $field->setValue('');
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects();

        $device = $this->reload($device);
        self::assertSame([], $device->getDiskLabels());
        self::assertNull($device->getDashboardDisk());

        $csrf = (string) $crawler->filter('#disks input[name=_token]')->attr('value');
        $this->client->request('POST', '/devices/'.$device->getId().'/disks', ['_token' => $csrf, 'dashboard_disk' => '/etc']);
        self::assertNull($this->reload($device)->getDashboardDisk());

        $this->client->request('GET', '/dashboard/cards');
        self::assertSelectorTextContains('.indicator dt', 'Dysk /mnt/backup');
    }

    public function testRequiresCsrf(): void
    {
        [$device] = $this->createDeviceWithToken();

        $this->client->request('POST', '/devices/'.$device->getId().'/disks', ['_token' => 'zły', 'dashboard_disk' => '/']);

        self::assertResponseStatusCodeSame(403);
    }

    private function heartbeat(IssuedToken $token, string $time): void
    {
        self::mockTime($time);
        $parsed = self::getContainer()->get(HeartbeatParser::class)->parse(self::PAYLOAD, new \DateTimeImmutable($time));
        // Token z bieżącego EntityManagera (jak w żądaniu API) – obiekt z fixture'a trzyma nieaktualne urządzenie.
        $fresh = $this->em()->find(DeviceToken::class, $token->token->getId());
        self::assertNotNull($fresh);
        self::getContainer()->get(HeartbeatIngestor::class)->ingest($fresh, $parsed, '203.0.113.7');
    }

    private function reload(Device $device): Device
    {
        $this->em()->clear();
        $fresh = $this->em()->find(Device::class, $device->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
