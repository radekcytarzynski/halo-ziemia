<?php

declare(strict_types=1);

namespace App\Alerting;

enum NotificationKind: string
{
    case None = 'none';
    /** Stan różni się od ostatnio zgłoszonego: problem, zmiana poziomu albo odzyskanie. */
    case Change = 'change';
    /** Problem trwa, minął interwał przypomnień. */
    case Reminder = 'reminder';
}
