<?php

declare(strict_types=1);

namespace App\Enum;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';

    public function toSeverity(): Severity
    {
        return match ($this) {
            self::Ok => Severity::Ok,
            self::Warn => Severity::Warn,
            self::Fail => Severity::Crit,
        };
    }
}
