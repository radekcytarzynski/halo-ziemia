<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiResponse
{
    public static function ok(\DateTimeImmutable $serverTime, int $interval): JsonResponse
    {
        return self::json([
            'ok' => true,
            'server_time' => $serverTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'interval' => $interval,
        ], 200);
    }

    /**
     * @param list<string>          $details
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $body = ['ok' => false, 'error' => $code, 'message' => $message];
        if ([] !== $details) {
            $body['details'] = $details;
        }

        return self::json($body, $status, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private static function json(array $body, int $status, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($body, $status, $headers);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
