<?php

declare(strict_types=1);

namespace App\Alerting;

/**
 * Dopasowanie celu reguły do klucza. Cel może zawierać jeden znak "*" (dowolny niepusty ciąg),
 * np. "disk.*.used_pct" pasuje do "disk./mnt/backup.used_pct" (dopasowanie: "/mnt/backup").
 */
final class RuleMatcher
{
    public static function isWildcard(string $target): bool
    {
        return str_contains($target, '*');
    }

    /**
     * @return string|null null = brak dopasowania; "" = dopasowanie dokładne; inaczej fragment dopasowany przez "*"
     */
    public static function match(string $target, string $key): ?string
    {
        if (!self::isWildcard($target)) {
            return $target === $key ? '' : null;
        }

        [$prefix, $suffix] = explode('*', $target, 2);
        if (str_contains($suffix, '*')) {
            return null;
        }

        $minLength = \strlen($prefix) + \strlen($suffix) + 1;
        if (\strlen($key) < $minLength || !str_starts_with($key, $prefix) || !str_ends_with($key, $suffix)) {
            return null;
        }

        return substr($key, \strlen($prefix), \strlen($key) - \strlen($prefix) - \strlen($suffix));
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, string> klucz → dopasowany fragment
     */
    public static function matchAll(string $target, iterable $keys): array
    {
        $matches = [];
        foreach ($keys as $key) {
            $captured = self::match($target, $key);
            if (null !== $captured) {
                $matches[$key] = $captured;
            }
        }

        return $matches;
    }
}
