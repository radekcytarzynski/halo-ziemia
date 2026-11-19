<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Odpowiedzi 401 dla API: brak tokenu (entry point) lub zły token (failure handler).
 */
final class ApiAuthenticationHandler implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized('Brak tokenu. Użyj nagłówka "Authorization: Bearer <token>".');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized('Nieprawidłowy lub unieważniony token.');
    }

    private function unauthorized(string $message): Response
    {
        return ApiResponse::error(401, 'unauthorized', $message, headers: ['WWW-Authenticate' => 'Bearer realm="halo-ziemia"']);
    }
}
