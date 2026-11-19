<?php

declare(strict_types=1);

namespace App\Security;

use App\Token\DeviceTokenManager;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Weryfikacja tokenu agenta z nagłówka "Authorization: Bearer hz_…".
 * Porównanie przez SHA-256 (w bazie nie ma jawnych tokenów).
 */
final readonly class DeviceTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private DeviceTokenManager $tokens)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->findValid($accessToken);
        if (null === $token) {
            throw new BadCredentialsException('Nieprawidłowy lub unieważniony token.');
        }

        $user = new DeviceUser($token);

        return new UserBadge($user->getUserIdentifier(), static fn (): DeviceUser => $user);
    }
}
