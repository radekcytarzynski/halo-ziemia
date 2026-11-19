<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Device;
use App\Entity\DeviceToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Tożsamość urządzenia uwierzytelnionego tokenem (tylko w firewallu API, bez sesji).
 */
final readonly class DeviceUser implements UserInterface
{
    public function __construct(private DeviceToken $token)
    {
    }

    public function getToken(): DeviceToken
    {
        return $this->token;
    }

    public function getDevice(): Device
    {
        return $this->token->getDevice();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_DEVICE'];
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return 'device-token:'.($this->token->getId() ?? 0);
    }
}
