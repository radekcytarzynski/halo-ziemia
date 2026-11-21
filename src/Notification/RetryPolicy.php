<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Backoff ponownych prób wysyłki. Po MAX_ATTEMPTS (~2 doby) wiadomość dostaje status failed.
 */
final class RetryPolicy
{
    public const int MAX_ATTEMPTS = 48;

    /** Opóźnienie po n-tej nieudanej próbie (dalej: ostatnia wartość). */
    private const array DELAYS = [60, 120, 300, 600, 1800, 3600];

    public function shouldGiveUp(int $attemptsMade): bool
    {
        return $attemptsMade >= self::MAX_ATTEMPTS;
    }

    public function delayAfter(int $attemptsMade): int
    {
        $index = max(0, $attemptsMade - 1);

        return self::DELAYS[min($index, \count(self::DELAYS) - 1)];
    }

    public function nextAttemptAt(\DateTimeImmutable $now, int $attemptsMade): \DateTimeImmutable
    {
        return $now->modify(\sprintf('+%d seconds', $this->delayAfter($attemptsMade)));
    }
}
