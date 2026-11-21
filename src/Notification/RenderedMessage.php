<?php

declare(strict_types=1);

namespace App\Notification;

final readonly class RenderedMessage
{
    public function __construct(
        public string $subject,
        public string $text,
        public ?string $html,
    ) {
    }
}
