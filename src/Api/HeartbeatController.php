<?php

declare(strict_types=1);

namespace App\Api;

use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use App\Ingest\Payload\ParsedHeartbeat;
use App\Ingest\Payload\PayloadValidationException;
use App\Security\DeviceUser;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * API agentów. Czas kontaktu = czas odbioru po stronie serwera (zegar agenta jest ignorowany).
 */
#[AsController]
#[Route('/api/v1', format: 'json')]
final readonly class HeartbeatController
{
    public const int MAX_BODY_BYTES = 65536;

    public function __construct(
        private HeartbeatParser $parser,
        private HeartbeatIngestor $ingestor,
        #[Target('api_device')]
        private RateLimiterFactoryInterface $limiter,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/heartbeat', name: 'api_heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request, #[CurrentUser] DeviceUser $user): JsonResponse
    {
        if (null !== $limited = $this->rateLimit($user)) {
            return $limited;
        }

        $declaredLength = $request->headers->get('Content-Length');
        if (null !== $declaredLength && (int) $declaredLength > self::MAX_BODY_BYTES) {
            return $this->tooLarge();
        }

        $content = $request->getContent();
        if (\strlen($content) > self::MAX_BODY_BYTES) {
            return $this->tooLarge();
        }

        try {
            $data = json_decode($content, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ApiResponse::error(422, 'invalid_json', 'Treść żądania nie jest poprawnym JSON-em.');
        }

        try {
            $heartbeat = $this->parser->parse($data, $this->clock->now());
        } catch (PayloadValidationException $e) {
            return ApiResponse::error(422, 'validation_failed', 'Payload nie przeszedł walidacji.', $e->getErrors());
        }

        return $this->accept($request, $user, $heartbeat);
    }

    #[Route('/ping', name: 'api_ping', methods: ['GET'])]
    public function ping(Request $request, #[CurrentUser] DeviceUser $user): JsonResponse
    {
        return $this->rateLimit($user) ?? $this->accept($request, $user, null);
    }

    private function accept(Request $request, DeviceUser $user, ?ParsedHeartbeat $heartbeat): JsonResponse
    {
        $receivedAt = $this->ingestor->ingest($user->getToken(), $heartbeat, $request->getClientIp());

        return ApiResponse::ok($receivedAt, $user->getDevice()->getExpectedIntervalSeconds());
    }

    private function rateLimit(DeviceUser $user): ?JsonResponse
    {
        // Klucz = hash tokenu: unikalny per token i niezależny od ID z bazy.
        $limit = $this->limiter->create($user->getToken()->getTokenHash())->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

        return ApiResponse::error(429, 'rate_limited', 'Zbyt wiele żądań dla tego tokenu.', headers: ['Retry-After' => (string) $retryAfter]);
    }

    private function tooLarge(): JsonResponse
    {
        return ApiResponse::error(413, 'payload_too_large', \sprintf('Maksymalny rozmiar treści to %d bajtów.', self::MAX_BODY_BYTES));
    }
}
