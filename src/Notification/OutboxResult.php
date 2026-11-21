<?php

declare(strict_types=1);

namespace App\Notification;

final readonly class OutboxResult
{
    public function __construct(
        public int $sent = 0,
        public int $retrying = 0,
        public int $failed = 0,
    ) {
    }
}
