<?php

declare(strict_types=1);

namespace App\Twig;

/**
 * Formatowanie wartości po polsku (czysta logika; używane w panelu i mailach).
 */
final class Formatter
{
    public static function number(float|int $value, int $maxDecimals = 1): string
    {
        $rounded = round((float) $value, $maxDecimals);
        $decimals = abs($rounded - round($rounded)) < 1e-9 ? 0 : $maxDecimals;

        return number_format($rounded, $decimals, ',', "\u{00A0}");
    }

    public static function bytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $i = 0;
        while (abs($value) >= 1000 && $i < \count($units) - 1) {
            $value /= 1000;
            ++$i;
        }

        return self::number($value, $value >= 100 || 0 === $i ? 0 : 1)."\u{00A0}".$units[$i];
    }

    /**
     * Czas trwania, np. "2 d 3 h", "1 h 30 min", "45 s" (dwie najbardziej znaczące jednostki).
     */
    public static function duration(float|int $seconds): string
    {
        $seconds = max(0, (int) round((float) $seconds));
        if ($seconds < 60) {
            return $seconds.' s';
        }

        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'min' => 60, 's' => 1] as $unit => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size).' '.$unit;
                $seconds %= $size;
            }
            if (2 === \count($parts)) {
                break;
            }
            if ([] !== $parts && 0 === $seconds) {
                break;
            }
        }

        return implode(' ', $parts);
    }

    public static function ago(\DateTimeImmutable $at, \DateTimeImmutable $now): string
    {
        $seconds = $now->getTimestamp() - $at->getTimestamp();
        if ($seconds < 0) {
            return 'przed chwilą';
        }
        if ($seconds < 10) {
            return 'przed chwilą';
        }

        return self::duration($seconds).' temu';
    }

    public static function metric(float|int $value, ?string $unit): string
    {
        return match ($unit) {
            'B' => self::bytes($value),
            's' => self::duration($value),
            'kB' => self::bytes((float) $value * 1000),
            null, '' => self::number($value, 2),
            '%' => self::number($value)."\u{00A0}%",
            default => self::number($value)."\u{00A0}".$unit,
        };
    }
}
