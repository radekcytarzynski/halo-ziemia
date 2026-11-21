<?php

declare(strict_types=1);

namespace App\Monitoring;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ping zewnętrznego watchdoga (np. healthchecks.io) po udanym ticku.
 * Gdy cron przestanie działać, pingi ustaną i zewnętrzna usługa wyśle alarm.
 */
final readonly class Watchdog
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(default::WATCHDOG_URL)%')]
        private ?string $url,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->url && '' !== $this->url;
    }

    public function ping(): bool
    {
        if (null === $this->url || '' === $this->url) {
            return false;
        }

        try {
            $status = $this->httpClient->request('GET', $this->url, ['timeout' => 10, 'max_duration' => 15])->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }
            $this->logger->warning('Watchdog odpowiedział statusem {status}.', ['status' => $status]);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Nie udało się wysłać pingu watchdoga: {message}', ['message' => $e->getMessage()]);
        }

        return false;
    }
}
