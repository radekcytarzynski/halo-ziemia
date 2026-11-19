<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Wszystkie czasy w aplikacji i w bazie są w UTC, niezależnie od ustawień hostingu.
        // Konwersja do strefy prezentacji odbywa się wyłącznie w warstwie widoku.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
