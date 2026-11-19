<?php

declare(strict_types=1);

namespace App\Http;

use App\Api\ApiResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wszystkie błędy pod /api/ zwracane jako JSON (bez stron HTML i bez szczegółów wyjątków).
 */
// Priorytet poniżej listenera Security (1), który zamienia błędy autoryzacji na 401 przez entry point.
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1)]
final readonly class ApiExceptionSubscriber
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $response = ApiResponse::error($status, match ($status) {
                404 => 'not_found',
                405 => 'method_not_allowed',
                default => 'http_error',
            }, match ($status) {
                404 => 'Nie znaleziono.',
                405 => 'Niedozwolona metoda HTTP.',
                default => 'Błąd żądania.',
            }, headers: array_map(
                static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '',
                array_filter($exception->getHeaders(), static fn (mixed $k): bool => \is_string($k), \ARRAY_FILTER_USE_KEY),
            ));
        } else {
            $this->logger->error('Błąd API: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
            $response = ApiResponse::error(500, 'server_error', 'Wewnętrzny błąd serwera.');
        }

        $event->setResponse($response);
    }
}
