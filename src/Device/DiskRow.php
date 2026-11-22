<?php

declare(strict_types=1);

namespace App\Device;

/**
 * Wiersz listy dysków urządzenia.
 */
final readonly class DiskRow
{
    public const string KIND_MOUNT = 'mount';
    public const string KIND_SMART = 'smart';
    public const string KIND_RAID = 'raid';
    /** Nazwany wcześniej, ale agent już go nie raportuje. */
    public const string KIND_GONE = 'gone';

    /**
     * @param string      $id     punkt montowania, nazwa dysku SMART (np. "sda") albo macierzy (np. "md0")
     * @param string|null $detail urządzenie partycji, ścieżka dysku albo stan macierzy
     * @param float|null  $value  zajętość w % (punkt montowania) albo temperatura w °C (SMART)
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $detail,
        public ?float $value,
        public ?string $label,
        public bool $reported,
    ) {
    }
}
