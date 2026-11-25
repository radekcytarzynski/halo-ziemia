<?php

declare(strict_types=1);

namespace App\Metrics;

final readonly class RollupResult
{
    /**
     * @param int      $hours liczba przetworzonych godzin (0 = nic do zrobienia)
     * @param int      $rows  wiersze dotknięte przez INSERT … ON DUPLICATE KEY UPDATE (MariaDB liczy aktualizację jako 2)
     * @param int|null $until koniec zagregowanego okresu (epoch), null = brak danych
     */
    public function __construct(
        public int $hours,
        public int $rows,
        public ?int $until,
    ) {
    }
}
