<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\ApiHttpsSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiHttpsSubscriberTest extends TestCase
{
    #[DataProvider('provider')]
    public function testHttpsEnforcement(bool $requireHttps, string $uri, ?int $expectedStatus): void
    {
        $event = new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($uri),
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new ApiHttpsSubscriber($requireHttps))($event);

        self::assertSame($expectedStatus, $event->getResponse()?->getStatusCode());
    }

    /**
     * @return iterable<string, array{bool, string, ?int}>
     */
    public static function provider(): iterable
    {
        yield 'API po HTTP w produkcji' => [true, 'http://example.test/api/v1/ping', 403];
        yield 'API po HTTPS' => [true, 'https://example.test/api/v1/ping', null];
        yield 'panel po HTTP nie jest blokowany' => [true, 'http://example.test/login', null];
        yield 'wymuszanie wyłączone' => [false, 'http://example.test/api/v1/ping', null];
    }
}
