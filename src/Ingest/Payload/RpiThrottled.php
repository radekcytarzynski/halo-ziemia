<?php

declare(strict_types=1);

namespace App\Ingest\Payload;

/**
 * Dekodowanie maski `vcgencmd get_throttled` (Raspberry Pi).
 */
final class RpiThrottled
{
    private const array FLAGS = [
        0 => 'Niskie napięcie (teraz)',
        1 => 'Ograniczona częstotliwość ARM (teraz)',
        2 => 'Dławienie (teraz)',
        3 => 'Miękki limit temperatury (teraz)',
        16 => 'Niskie napięcie (wystąpiło)',
        17 => 'Ograniczona częstotliwość ARM (wystąpiło)',
        18 => 'Dławienie (wystąpiło)',
        19 => 'Miękki limit temperatury (wystąpiło)',
    ];

    public static function parse(string $value): ?int
    {
        if (1 !== preg_match('/^0x[0-9a-fA-F]{1,8}$/D', $value)) {
            return null;
        }

        return (int) hexdec(substr($value, 2));
    }

    /**
     * @return list<string>
     */
    public static function describe(int $mask): array
    {
        $flags = [];
        foreach (self::FLAGS as $bit => $label) {
            if (0 !== ($mask & (1 << $bit))) {
                $flags[] = $label;
            }
        }

        return $flags;
    }
}
