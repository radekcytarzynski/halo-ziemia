<?php

declare(strict_types=1);

namespace App\Enum;

enum RuleOperator: string
{
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case Eq = 'eq';
    case Ne = 'ne';

    public function symbol(): string
    {
        return match ($this) {
            self::Gt => '>',
            self::Gte => '≥',
            self::Lt => '<',
            self::Lte => '≤',
            self::Eq => '=',
            self::Ne => '≠',
        };
    }
}
