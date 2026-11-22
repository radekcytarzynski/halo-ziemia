<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Healthcheck dla skryptu wdrożeniowego i zewnętrznego monitoringu. Bez danych wrażliwych.
 * "secure" pozwala wykryć, że aplikacja za proxy nie widzi HTTPS (wtedy API odrzucałoby agentów).
 */
#[AsController]
final readonly class HealthController
{
    public function __construct(private Connection $connection)
    {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(Request $request): JsonResponse
    {
        return $this->json(['status' => 'ok', 'secure' => $request->isSecure()], 200);
    }

    #[Route('/health/db', name: 'health_db', methods: ['GET'])]
    public function database(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            return $this->json(['status' => 'error'], 503);
        }

        return $this->json(['status' => 'ok'], 200);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
