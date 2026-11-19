<?php

declare(strict_types=1);

namespace App\Enum;

enum EventType: string
{
    case StateChange = 'state_change';
    case Reminder = 'reminder';
    case NotificationSent = 'notification_sent';
    case NotificationFailed = 'notification_failed';
    case ConfigChange = 'config_change';
    case TokenCreated = 'token_created';
    case TokenRevoked = 'token_revoked';
    case Maintenance = 'maintenance';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::StateChange => 'Zmiana stanu',
            self::Reminder => 'Przypomnienie',
            self::NotificationSent => 'Wysłano powiadomienie',
            self::NotificationFailed => 'Błąd powiadomienia',
            self::ConfigChange => 'Zmiana konfiguracji',
            self::TokenCreated => 'Utworzono token',
            self::TokenRevoked => 'Unieważniono token',
            self::Maintenance => 'Tryb serwisowy',
            self::System => 'System',
        };
    }
}
