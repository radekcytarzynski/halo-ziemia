<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\Severity;

/**
 * Pozycja powiadomienia: jedna zmiana (lub trwający problem) jednego podmiotu.
 */
final readonly class AlertItem
{
    public function __construct(
        public string $title,
        public Severity $from,
        public Severity $to,
        public ?string $detail,
        public \DateTimeImmutable $since,
    ) {
    }
}
