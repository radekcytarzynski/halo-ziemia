<?php

declare(strict_types=1);

namespace App\Token;

final readonly class GeneratedToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $plainText,
        public string $displayPrefix,
        public string $hash,
    ) {
    }
}
