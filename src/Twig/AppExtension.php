<?php

declare(strict_types=1);

namespace App\Twig;

use App\Metrics\MetricUnit;
use Psr\Clock\ClockInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class AppExtension
{
    public function __construct(private ClockInterface $clock)
    {
    }

    #[AsTwigFilter('hz_number')]
    public function number(float|int $value, int $maxDecimals = 1): string
    {
        return Formatter::number($value, $maxDecimals);
    }

    #[AsTwigFilter('hz_bytes')]
    public function bytes(float|int $value): string
    {
        return Formatter::bytes($value);
    }

    #[AsTwigFilter('hz_duration')]
    public function duration(float|int $seconds): string
    {
        return Formatter::duration($seconds);
    }

    #[AsTwigFilter('hz_ago')]
    public function ago(?\DateTimeImmutable $at): string
    {
        return null === $at ? 'nigdy' : Formatter::ago($at, $this->clock->now());
    }

    /**
     * Wartość metryki z jednostką; jednostka zgadywana z klucza, jeśli nie podano.
     */
    #[AsTwigFilter('hz_metric')]
    public function metric(float|int $value, ?string $unitOrKey = null): string
    {
        $unit = null !== $unitOrKey && str_contains($unitOrKey, '.') ? MetricUnit::guess($unitOrKey) : $unitOrKey;

        return Formatter::metric($value, $unit);
    }

    #[AsTwigFunction('hz_now')]
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
