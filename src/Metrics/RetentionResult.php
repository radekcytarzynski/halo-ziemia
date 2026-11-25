<?php

declare(strict_types=1);

namespace App\Metrics;

final readonly class RetentionResult
{
    /**
     * @param int|null $rawCutoff  granica surowych próbek (epoch); null = pominięte, bo rollup jeszcze nie działał
     * @param int      $longCutoff granica rollupów, zdarzeń i powiadomień (epoch)
     */
    public function __construct(
        public int $samples,
        public int $hourly,
        public int $events,
        public int $incidents,
        public int $notifications,
        public ?int $rawCutoff,
        public int $longCutoff,
    ) {
    }
}
