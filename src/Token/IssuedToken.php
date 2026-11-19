<?php

declare(strict_types=1);

namespace App\Token;

use App\Entity\DeviceToken;

/**
 * Wynik utworzenia tokenu: jawna wartość jest dostępna tylko tutaj (pokazywana jeden raz).
 */
final readonly class IssuedToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $plainText,
        public DeviceToken $token,
    ) {
    }
}
