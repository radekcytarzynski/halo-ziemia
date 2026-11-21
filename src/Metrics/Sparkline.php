<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Miniwykres jako ścieżka SVG (bez JS i bez stylów inline – kolory z CSS).
 */
final class Sparkline
{
    public const int WIDTH = 120;
    public const int HEIGHT = 32;

    /**
     * @param list<array{int, float|null, float|null, float|null}> $points [ts, min, avg, max]
     *
     * @return string|null atrybut "d" ścieżki SVG albo null, gdy za mało danych
     */
    public static function path(array $points, int $from, int $to): ?string
    {
        $values = array_values(array_filter($points, static fn (array $p): bool => null !== $p[2]));
        if (\count($values) < 2 || $to <= $from) {
            return null;
        }

        $avgs = array_map(static fn (array $p): float => (float) $p[2], $values);
        $min = min($avgs);
        $max = max($avgs);
        $span = $max - $min;
        if ($span < 1e-9) {
            --$min;
            $span = 2;
        }

        $pad = 2;
        $d = '';
        $penUp = true;
        foreach ($points as $point) {
            if (null === $point[2]) {
                $penUp = true;
                continue;
            }
            $x = ($point[0] - $from) / ($to - $from) * self::WIDTH;
            $y = $pad + (1 - ($point[2] - $min) / $span) * (self::HEIGHT - 2 * $pad);
            $d .= \sprintf('%s%.1f %.1f ', $penUp ? 'M' : 'L', max(0.0, min((float) self::WIDTH, $x)), $y);
            $penUp = false;
        }

        return trim($d);
    }
}
