<?php

declare(strict_types=1);

namespace App\Enum;

enum RuleSource: string
{
    /** Reguła progowa na metryce numerycznej (target = klucz metryki). */
    case Metric = 'metric';
    /** Reguła na statusie checka agenta (target = nazwa checka). */
    case Check = 'check';
}
