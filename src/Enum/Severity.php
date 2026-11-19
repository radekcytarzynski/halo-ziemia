<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Stan składowy (reguła, check, heartbeat). Porządek: OK < WARN < CRIT < DOWN.
 */
enum Severity: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Crit = 'crit';
    case Down = 'down';

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warn => 1,
            self::Crit => 2,
            self::Down => 3,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public static function worst(self ...$severities): self
    {
        $worst = self::Ok;
        foreach ($severities as $severity) {
            if ($severity->isWorseThan($worst)) {
                $worst = $severity;
            }
        }

        return $worst;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Warn => 'WARN',
            self::Crit => 'CRIT',
            self::Down => 'DOWN',
        };
    }
}
