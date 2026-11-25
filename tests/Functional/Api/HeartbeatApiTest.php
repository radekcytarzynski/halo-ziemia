<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\HeartbeatController;
use App\Entity\CheckResult;
use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Enum\CheckStatus;
use App\Tests\Functional\DeviceFixtures;
use App\Tests\Unit\Ingest\HeartbeatParserTest;
use App\Token\DeviceTokenManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class HeartbeatApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DeviceFixtures;

    private const string NOW = '2026-11-19 12:00:00';

    private KernelBrowser $client;
    private Device $device;
    private string $token;

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->client = self::createClient();
        [$this->device, $issued] = $this->createDeviceWithToken();
        $this->token = $issued->plainText;
    }

    public function testHeartbeatStoresSamplesSnapshotAndChecks(): void
    {
        $this->postHeartbeat(HeartbeatParserTest::examplePayload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ok' => true, 'server_time' => '2026-11-19T12:00:00Z', 'interval' => 60], $this->json());
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'API nie może ustawiać ciasteczek');

        $device = $this->reloadDevice();
        self::assertEquals(new \DateTimeImmutable(self::NOW), $device->getLastSeenAt());
        self::assertSame('1.0.0', $device->getLastAgentVersion());
        $snapshot = $device->getLastSnapshot();
        self::assertIsArray($snapshot);
        self::assertSame('nas', $snapshot['hostname']);
        self::assertIsArray($snapshot['metrics']);
        self::assertEquals(40.0, $snapshot['metrics']['disk./.used_pct']);

        $samples = $this->connection()->fetchAllKeyValue(
            'SELECT m.metric_key, s.value FROM sample s JOIN metric m ON m.id = s.metric_id WHERE s.device_id = ? AND s.ts = ?',
            [$device->getId(), (new \DateTimeImmutable(self::NOW))->getTimestamp()],
        );
        self::assertCount(22, $samples);
        self::assertEquals(62.5, $samples['mem.available_pct']);
        self::assertEquals(23.5, $samples['cpu.used_pct']);
        self::assertSame('%', $this->connection()->fetchOne("SELECT unit FROM metric WHERE metric_key = 'disk./.used_pct'"));

        $check = $this->em()->getRepository(CheckResult::class)->findOneBy(['device' => $device, 'name' => 'backup']);
        self::assertNotNull($check);
        self::assertSame(CheckStatus::Ok, $check->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-19 10:30:00'), $check->getLastSuccessAt());

        $token = $this->em()->getRepository(DeviceToken::class)->findOneBy(['device' => $device]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $token?->getLastUsedAt());
    }

    public function testRepeatedHeartbeatUpdatesChecksAndKeepsLastSuccess(): void
    {
        $this->postHeartbeat(['schema' => 1, 'checks' => [['name' => 'backup', 'status' => 'ok']]]);
        self::mockTime('2026-11-19 12:01:00');
        $this->postHeartbeat(['schema' => 1, 'checks' => [['name' => 'backup', 'status' => 'fail', 'message' => 'rsync: błąd 23']]]);

        self::assertResponseIsSuccessful();
        $check = $this->em()->getRepository(CheckResult::class)->findOneBy(['device' => $this->device, 'name' => 'backup']);
        self::assertNotNull($check);
        self::assertSame(CheckStatus::Fail, $check->getStatus());
        self::assertSame('rsync: błąd 23', $check->getMessage());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $check->getLastSuccessAt());
        self::assertSame(1, $this->em()->getRepository(CheckResult::class)->count(['device' => $this->device]));
    }

    public function testServerTimeIsUsedNotAgentTime(): void
    {
        $this->postHeartbeat(['schema' => 1, 'timestamp' => '1999-01-01T00:00:00Z', 'uptime_s' => 5]);

        self::assertResponseIsSuccessful();
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->reloadDevice()->getLastSeenAt());
    }

    public function testPingUpdatesLastSeenWithoutSamples(): void
    {
        $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($this->token));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['ok'] ?? false);
        $device = $this->reloadDevice();
        self::assertEquals(new \DateTimeImmutable(self::NOW), $device->getLastSeenAt());
        self::assertNull($device->getLastSnapshot());
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM sample'));
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('unauthorizedProvider')]
    public function testUnauthorized(string $method, string $uri, array $server): void
    {
        $this->client->request($method, $uri, server: $server, content: '{"schema":1}');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="halo-ziemia"');
        self::assertSame('unauthorized', $this->json()['error'] ?? null);
        self::assertNull($this->reloadDevice()->getLastSeenAt());
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function unauthorizedProvider(): iterable
    {
        yield 'brak nagłówka' => ['POST', '/api/v1/heartbeat', []];
        yield 'brak nagłówka (ping)' => ['GET', '/api/v1/ping', []];
        yield 'zły schemat nagłówka' => ['GET', '/api/v1/ping', ['HTTP_AUTHORIZATION' => 'Basic abc']];
        yield 'token w złym formacie' => ['GET', '/api/v1/ping', ['HTTP_AUTHORIZATION' => 'Bearer secret']];
        yield 'nieznany token' => ['POST', '/api/v1/heartbeat', ['HTTP_AUTHORIZATION' => 'Bearer hz_'.str_repeat('Q', 48)]];
    }

    public function testRevokedTokenIsRejected(): void
    {
        $manager = self::getContainer()->get(DeviceTokenManager::class);
        $token = $manager->findValid($this->token);
        self::assertNotNull($token);
        $manager->revoke($token);

        $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($this->token));

        self::assertResponseStatusCodeSame(401);
    }

    public function testRotationKeepsOldTokenValidDuringGracePeriod(): void
    {
        $manager = self::getContainer()->get(DeviceTokenManager::class);
        $device = $this->em()->find(Device::class, $this->device->getId());
        self::assertNotNull($device);
        $new = $manager->rotate($device, 3600);

        self::mockTime('2026-11-19 12:59:59');
        $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($this->token));
        self::assertResponseStatusCodeSame(200, 'stary token działa w oknie przejściowym');

        self::mockTime('2026-11-19 13:00:00');
        $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($this->token));
        self::assertResponseStatusCodeSame(401, 'stary token wygasa po oknie');

        $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($new->plainText));
        self::assertResponseStatusCodeSame(200, 'nowy token działa');
    }

    #[DataProvider('invalidBodyProvider')]
    public function testInvalidBody(string $body, string $expectedError, ?string $expectedDetail): void
    {
        $this->client->request('POST', '/api/v1/heartbeat', server: $this->authHeader($this->token) + ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(422);
        $json = $this->json();
        self::assertSame($expectedError, $json['error'] ?? null);
        if (null !== $expectedDetail) {
            self::assertIsArray($json['details'] ?? null);
            self::assertContains($expectedDetail, $json['details']);
        }
        self::assertNull($this->reloadDevice()->getLastSeenAt(), 'odrzucony heartbeat nie liczy się jako kontakt');
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function invalidBodyProvider(): iterable
    {
        yield 'pusta treść' => ['', 'invalid_json', null];
        yield 'nie JSON' => ['schema=1', 'invalid_json', null];
        yield 'zbyt głęboki JSON' => [str_repeat('[', 20).str_repeat(']', 20), 'invalid_json', null];
        yield 'tablica' => ['[1,2]', 'validation_failed', '$: oczekiwano obiektu JSON'];
        yield 'brak schema' => ['{"hostname":"nas"}', 'validation_failed', 'schema: wymagana wartość 1'];
        yield 'zły typ' => ['{"schema":1,"uptime_s":"dużo"}', 'validation_failed', 'uptime_s: oczekiwano liczby'];
    }

    public function testTooLargeBodyIsRejected(): void
    {
        $body = json_encode(['schema' => 1, 'hostname' => str_repeat('x', HeartbeatController::MAX_BODY_BYTES)], \JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/api/v1/heartbeat', server: $this->authHeader($this->token), content: $body);

        self::assertResponseStatusCodeSame(413);
        self::assertSame('payload_too_large', $this->json()['error'] ?? null);
    }

    public function testRateLimitPerToken(): void
    {
        $this->client->disableReboot();
        for ($i = 0; $i < 12; ++$i) {
            $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($this->token));
            self::assertResponseIsSuccessful();
        }

        $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($this->token));

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->json()['error'] ?? null);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));

        [, $other] = $this->createDeviceWithToken('Raspberry Pi');
        $this->client->request('GET', '/api/v1/ping', server: $this->authHeader($other->plainText));
        self::assertResponseIsSuccessful('limit jest per token');
    }

    public function testWrongMethodReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/heartbeat', server: $this->authHeader($this->token));

        self::assertResponseStatusCodeSame(405);
        self::assertSame('method_not_allowed', $this->json()['error'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postHeartbeat(array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/v1/heartbeat',
            server: $this->authHeader($this->token) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, string>
     */
    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function reloadDevice(): Device
    {
        $this->em()->clear();
        $device = $this->em()->find(Device::class, $this->device->getId());
        self::assertNotNull($device);

        return $device;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
