<?php

declare(strict_types=1);

namespace App\Enum;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    /** Wyczerpany limit prób. */
    case Failed = 'failed';
}
