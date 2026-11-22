<?php

declare(strict_types=1);

namespace App\Device;

use App\Entity\Device;

/**
 * Lista dysków urządzenia do nadawania nazw: punkty montowania, dyski SMART i macierze RAID
 * z ostatniego heartbeatu, plus nazwane wcześniej dyski, których agent już nie raportuje.
 */
final class DiskInventory
{
    public const int MAX_LABEL_LENGTH = 50;

    /**
     * @return list<DiskRow>
     */
    public static function rows(Device $device): array
    {
        $snapshot = $device->getLastSnapshot() ?? [];
        $metrics = \is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [];
        $rows = [];

        foreach (self::entries($snapshot, 'disks') as $disk) {
            $mount = $disk['mount'] ?? null;
            if (!\is_string($mount)) {
                continue;
            }
            $partition = \is_string($disk['device'] ?? null) ? $disk['device'] : null;
            $rows[$mount] = new DiskRow($mount, DiskRow::KIND_MOUNT, $partition, self::number($metrics, 'disk.'.$mount.'.used_pct'), $device->getDiskLabel($mount), true);
        }
        foreach (self::entries($snapshot, 'smart') as $smart) {
            $name = $smart['name'] ?? null;
            if (!\is_string($name) || isset($rows[$name])) {
                continue;
            }
            $rows[$name] = new DiskRow($name, DiskRow::KIND_SMART, '/dev/'.$name, self::number($metrics, 'smart.'.$name.'.temp_c'), $device->getDiskLabel($name), true);
        }
        foreach (self::entries($snapshot, 'raid') as $raid) {
            $name = $raid['name'] ?? null;
            if (!\is_string($name) || isset($rows[$name])) {
                continue;
            }
            $state = \is_string($raid['state'] ?? null) ? $raid['state'] : null;
            $rows[$name] = new DiskRow($name, DiskRow::KIND_RAID, $state, null, $device->getDiskLabel($name), true);
        }
        foreach ($device->getDiskLabels() as $id => $label) {
            $rows[$id] ??= new DiskRow($id, DiskRow::KIND_GONE, null, null, $label, false);
        }

        return array_values($rows);
    }

    /**
     * Punkty montowania raportowane przez agenta (kandydaci na dysk karty dashboardu).
     *
     * @return list<string>
     */
    public static function mounts(Device $device): array
    {
        return array_values(array_map(
            static fn (DiskRow $row): string => $row->id,
            array_filter(self::rows($device), static fn (DiskRow $row): bool => DiskRow::KIND_MOUNT === $row->kind),
        ));
    }

    /**
     * Nazwa z formularza: bez znaków sterujących i nadmiarowych spacji, przycięta do limitu. "" = brak nazwy.
     */
    public static function normalizeLabel(string $label): string
    {
        $label = (string) preg_replace('/[\p{C}\s]+/u', ' ', $label);

        return mb_substr(trim($label), 0, self::MAX_LABEL_LENGTH);
    }

    /**
     * @param array<array-key, mixed> $snapshot
     *
     * @return list<array<array-key, mixed>>
     */
    private static function entries(array $snapshot, string $section): array
    {
        $entries = $snapshot[$section] ?? null;

        return \is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * @param array<array-key, mixed> $metrics
     */
    private static function number(array $metrics, string $key): ?float
    {
        $value = $metrics[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }
}
