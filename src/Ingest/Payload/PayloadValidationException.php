<?php

declare(strict_types=1);

namespace App\Ingest\Payload;

final class PayloadValidationException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Nieprawidłowy payload: '.implode('; ', $errors));
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
