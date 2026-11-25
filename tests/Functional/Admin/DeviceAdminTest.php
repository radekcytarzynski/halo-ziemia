<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AlertRule;
use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Enum\DeviceStatus;
use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use App\Token\DeviceTokenManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Field\FormField;

final class DeviceAdminTest extends PanelTestCase
{
    public function testCreateDeviceWithDefaultRules(): void
    {
        $crawler = $this->client->request('GET', '/devices/new');
        $this->client->submit($crawler->selectButton('Dodaj urządzenie')->form([
            'device[name]' => 'Raspberry Pi 4',
            'device[slug]' => '',
            'device[expectedIntervalSeconds]' => '60',
        ]));

        $device = $this->em()->getRepository(Device::class)->findOneBy(['slug' => 'raspberry-pi-4']);
        self::assertNotNull($device);
        self::assertResponseRedirects('/devices/'.$device->getId().'#tokens');
        self::assertGreaterThan(5, $this->em()->getRepository(AlertRule::class)->count(['device' => $device]));
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('invalidDeviceProvider')]
    public function testDeviceValidation(array $fields, string $expectedError): void
    {
        $this->createDeviceWithToken('Istniejące');
        $crawler = $this->client->request('GET', '/devices/new');
        $this->client->submit($crawler->selectButton('Dodaj urządzenie')->form($fields));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-errors', $expectedError);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidDeviceProvider(): iterable
    {
        yield 'pusta nazwa' => [['device[name]' => ''], 'nie powinna być pusta'];
        yield 'zły slug' => [['device[name]' => 'X', 'device[slug]' => 'Zły Slug'], 'Dozwolone: małe litery'];
        yield 'duplikat sluga' => [['device[name]' => 'Inne', 'device[slug]' => 'istniejace'], 'już istnieje'];
        yield 'za krótki interwał' => [['device[name]' => 'X', 'device[expectedIntervalSeconds]' => '5'], 'między 10 a 86400'];
    }

    public function testShowPageSectionsAndActiveRules(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $parsed = self::getContainer()->get(HeartbeatParser::class)->parse(
            ['schema' => 1, 'hostname' => 'nas', 'cpu_temp_c' => 40, 'disks' => [['mount' => '/', 'size_b' => 100, 'used_b' => 50]], 'checks' => [['name' => 'backup', 'status' => 'ok']]],
            new \DateTimeImmutable('2026-11-20 12:00:00'),
        );
        self::getContainer()->get(HeartbeatIngestor::class)->ingest($token->token, $parsed, '10.0.0.5');

        $crawler = $this->client->request('GET', '/devices/'.$device->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'NAS Debian');
        self::assertSelectorTextContains('#overview', '10.0.0.5');
        self::assertSelectorTextContains('#overview', 'alarm po 3 min 30 s ciszy');
        self::assertSelectorTextContains('#overview', 'backup');
        $chartUrls = $crawler->filter('[data-chart-url]')->each(static fn ($n): string => urldecode((string) $n->attr('data-chart-url')));
        self::assertContains('/devices/'.$device->getId().'/chart?metric=disk./.used_pct&range=24h', $chartUrls);
        $activeRules = $crawler->filter('#rules > .table-wrap')->text();
        self::assertStringContainsString('Zajętość dysku', $activeRules);
        self::assertStringContainsString('Temperatura CPU', $activeRules);
        self::assertStringNotContainsString('RAID', $activeRules, 'RAID nie pasuje do metryk – w sekcji nieużywanych');
        self::assertSelectorTextContains('#rules details summary', 'Nieużywane');
        self::assertSelectorTextContains('#timeline', 'Dodano urządzenie');
    }

    public function testChartEndpointReturnsBuckets(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $parser = self::getContainer()->get(HeartbeatParser::class);
        $ingestor = self::getContainer()->get(HeartbeatIngestor::class);
        foreach (['11:50:00' => 40, '11:51:00' => 42, '11:59:30' => 44] as $time => $temp) {
            self::mockTime('2026-11-20 '.$time);
            $ingestor->ingest($token->token, $parser->parse(['schema' => 1, 'cpu_temp_c' => $temp], new \DateTimeImmutable('2026-11-20 '.$time)), null);
        }
        self::mockTime('2026-11-20 12:00:00');

        $this->client->request('GET', '/devices/'.$device->getId().'/chart?metric=cpu.temp_c&range=24h');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('°C', $data['unit']);
        self::assertSame(60, $data['bucket']);
        $ts = static fn (string $t): int => (new \DateTimeImmutable('2026-11-20 '.$t))->getTimestamp();
        self::assertEquals([
            [$ts('11:50:00'), 40, 40, 40],
            [$ts('11:51:00'), 42, 42, 42],
            [$ts('11:52:00'), null, null, null],
            [$ts('11:59:00'), 44, 44, 44],
        ], $data['points'], 'przerwa dłuższa niż 2,5 kubełka jest oznaczona nullem');

        $this->client->request('GET', '/devices/'.$device->getId().'/chart?metric=nie.istnieje');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTokenLifecycleFromPanel(): void
    {
        [$device] = $this->createDeviceWithToken();
        $url = '/devices/'.$device->getId();

        // Rotacja: nowy token pokazany raz, stary z terminem ważności
        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('Rotuj token')->form(['grace' => '3600']));
        self::assertResponseRedirects($url.'#tokens');
        $crawler = $this->client->followRedirect();
        $newToken = trim($crawler->filter('#new-token')->text());
        self::assertMatchesRegularExpression('/^hz_[0-9A-Za-z]{48}$/', $newToken);
        self::assertStringContainsString($newToken, $crawler->filter('#snippet-test')->text(), 'snippet z wstawionym tokenem');
        self::assertSelectorTextContains('#tokens', 'wygasa 2026-11-20 14:00');

        $this->client->request('GET', $url);
        self::assertSelectorNotExists('#new-token', 'token pokazywany tylko raz');

        $tokens = self::getContainer()->get(DeviceTokenManager::class);
        self::assertNotNull($tokens->findValid($newToken));

        // Unieważnienie wszystkich
        foreach ($this->em()->getRepository(DeviceToken::class)->findBy(['device' => $device]) as $t) {
            if (null === $t->getRevokedAt()) {
                $this->clickPostButton($url, 'Unieważnij');
            }
        }
        self::assertNull($tokens->findValid($newToken));
        $crawler = $this->client->request('GET', $url);
        self::assertCount(1, $crawler->selectButton('Utwórz token'));
    }

    public function testMaintenanceAndToggle(): void
    {
        [$device] = $this->createDeviceWithToken();
        $url = '/devices/'.$device->getId();

        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('Włącz tryb serwisowy')->form(['minutes' => '120']));
        self::assertResponseRedirects($url);
        $this->em()->clear();
        $reloaded = $this->em()->find(Device::class, $device->getId());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 14:00:00'), $reloaded?->getMutedUntil());

        $this->clickPostButton($url, 'Zakończ teraz');
        $this->clickPostButton($url, 'Wyłącz monitorowanie');
        $this->em()->clear();
        $reloaded = $this->em()->find(Device::class, $device->getId());
        self::assertNull($reloaded?->getMutedUntil());
        self::assertFalse($reloaded?->isEnabled());
    }

    public function testInvalidMuteDurationIsRejected(): void
    {
        [$device] = $this->createDeviceWithToken();
        $crawler = $this->client->request('GET', '/devices/'.$device->getId());
        $form = $crawler->selectButton('Włącz tryb serwisowy')->form();
        $token = $form->get('_token');
        self::assertInstanceOf(FormField::class, $token);
        $this->client->request('POST', $form->getUri(), ['_token' => $token->getValue(), 'minutes' => '999999']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testActionsRequireValidCsrf(): void
    {
        [$device] = $this->createDeviceWithToken();
        $this->client->request('POST', '/devices/'.$device->getId().'/delete', ['_token' => 'zły']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em()->find(Device::class, $device->getId()));
    }

    public function testEditAndDeleteDevice(): void
    {
        [$device] = $this->createDeviceWithToken();
        $id = $device->getId();

        $crawler = $this->client->request('GET', '/devices/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Zapisz')->form([
            'device[name]' => 'NAS (piwnica)',
            'device[missedIntervalsThreshold]' => '5',
        ]));
        self::assertResponseRedirects('/devices/'.$id);
        $this->em()->clear();
        self::assertSame(5, $this->em()->find(Device::class, $id)?->getMissedIntervalsThreshold());

        // Tabele próbek nie mają kluczy obcych – DeviceManager czyści je jawnie (próbki innego urządzenia zostają)
        $db = $this->em()->getConnection();
        foreach ([$id, 999999] as $deviceId) {
            $db->insert('sample', ['device_id' => $deviceId, 'metric_id' => 1, 'ts' => 1_700_000_000, 'value' => 1]);
            $db->insert('sample_hourly', ['device_id' => $deviceId, 'metric_id' => 1, 'hour_ts' => 1_699_999_200, 'min_value' => 1, 'avg_value' => 1, 'max_value' => 1, 'sample_count' => 1]);
        }

        $this->clickPostButton('/devices/'.$id.'/edit', 'Usuń urządzenie');
        self::assertResponseRedirects('/devices');
        $this->em()->clear();
        self::assertNull($this->em()->find(Device::class, $id));
        $db = $this->em()->getConnection();
        self::assertSame([999999], array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : -1, $db->fetchFirstColumn('SELECT device_id FROM sample')));
        self::assertSame([999999], array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : -1, $db->fetchFirstColumn('SELECT device_id FROM sample_hourly')));
        self::assertSame(DeviceStatus::Pending, $device->getStatus());
    }
}
