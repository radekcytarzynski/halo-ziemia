<?php

declare(strict_types=1);

namespace App\Ingest\Payload;

use App\Enum\CheckStatus;

final readonly class CheckReport
{
    public function __construct(
        public string $name,
        public CheckStatus $status,
        public ?string $message,
        public ?int $lastSuccessAgeSeconds,
    ) {
    }
}
