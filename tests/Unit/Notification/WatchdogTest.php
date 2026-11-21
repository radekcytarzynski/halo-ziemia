<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Monitoring\Watchdog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WatchdogTest extends TestCase
{
    #[DataProvider('provider')]
    public function testPing(?string $url, ?MockResponse $response, bool $expected, int $expectedRequests): void
    {
        $client = new MockHttpClient(null !== $response ? [$response] : []);

        self::assertSame($expected, (new Watchdog($client, new NullLogger(), $url))->ping());
        self::assertSame($expectedRequests, $client->getRequestsCount());
    }

    /**
     * @return iterable<string, array{?string, ?MockResponse, bool, int}>
     */
    public static function provider(): iterable
    {
        yield 'wyłączony (null)' => [null, null, false, 0];
        yield 'wyłączony (pusty)' => ['', null, false, 0];
        yield 'OK' => ['https://hc.example/ping/abc', new MockResponse('OK', ['http_code' => 200]), true, 1];
        yield 'błąd HTTP' => ['https://hc.example/ping/abc', new MockResponse('', ['http_code' => 503]), false, 1];
        yield 'błąd sieci' => ['https://hc.example/ping/abc', new MockResponse('', ['error' => 'host unreachable']), false, 1];
    }
}
