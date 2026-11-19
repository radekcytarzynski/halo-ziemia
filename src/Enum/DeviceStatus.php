<?php

declare(strict_types=1);

namespace App\Enum;

enum DeviceStatus: string
{
    /** Nowe urządzenie, jeszcze bez heartbeatu: bez alarmów. */
    case Pending = 'pending';
    case Ok = 'ok';
    case Warn = 'warn';
    case Crit = 'crit';
    case Down = 'down';
    /** Urządzenie wyłączone (enabled = false). */
    case Paused = 'paused';

    public static function fromSeverity(Severity $severity): self
    {
        return match ($severity) {
            Severity::Ok => self::Ok,
            Severity::Warn => self::Warn,
            Severity::Crit => self::Crit,
            Severity::Down => self::Down,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Oczekuje',
            self::Ok => 'OK',
            self::Warn => 'Ostrzeżenie',
            self::Crit => 'Krytyczny',
            self::Down => 'Brak kontaktu',
            self::Paused => 'Wyłączone',
        };
    }
}
