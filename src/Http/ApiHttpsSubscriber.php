<?php

declare(strict_types=1);

namespace App\Http;

use App\Api\ApiResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * W produkcji API przyjmuje wyłącznie HTTPS. Bez przekierowania: token wysłany po HTTP
 * i tak już wyciekł, więc odpowiadamy błędem, żeby agent/administrator to zauważył.
 * Priorytet wyższy niż firewall (8), więc token nie jest nawet sprawdzany.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 40)]
final readonly class ApiHttpsSubscriber
{
    public function __construct(
        #[Autowire('%env(bool:API_REQUIRE_HTTPS)%')]
        private bool $requireHttps,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->requireHttps || !$event->isMainRequest() || $request->isSecure()) {
            return;
        }
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $event->setResponse(ApiResponse::error(403, 'https_required', 'API wymaga połączenia HTTPS.'));
    }
}
