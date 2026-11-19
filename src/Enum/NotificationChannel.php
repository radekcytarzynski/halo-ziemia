<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Kanały powiadomień. Nowy kanał = nowy case + implementacja NotifierInterface.
 */
enum NotificationChannel: string
{
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
        };
    }
}
