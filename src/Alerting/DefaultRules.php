<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Entity\AlertRule;
use App\Entity\Device;
use App\Enum\RuleOperator;
use App\Enum\RuleSource;

/**
 * Szablony reguł tworzone dla nowego urządzenia. Progi można potem zmienić w panelu.
 * Wildcardy sprawiają, że reguły pasują do dowolnych dysków/macierzy bez konfiguracji klienta.
 */
final class DefaultRules
{
    /**
     * @return list<array{source: RuleSource, target: string, label: string, operator: RuleOperator, warn: float|null, crit: float|null, consecutive: int, hysteresis: float}>
     */
    public static function templates(): array
    {
        return [
            self::metric('disk.*.used_pct', 'Zajętość dysku', RuleOperator::Gt, 85, 95, 2, 2),
            self::metric('disk.*.inodes_used_pct', 'Zajętość i-węzłów', RuleOperator::Gt, 85, 95, 2, 2),
            self::metric('mem.available_pct', 'Dostępna pamięć', RuleOperator::Lt, 10, 5, 3, 2),
            self::metric('cpu.temp_c', 'Temperatura CPU', RuleOperator::Gt, 70, 80, 3, 3),
            self::metric('smart.*.temp_c', 'Temperatura dysku', RuleOperator::Gt, 50, 60, 3, 2),
            self::metric('smart.*.passed', 'SMART', RuleOperator::Lt, null, 1, 1, 0),
            self::metric('smart.*.reallocated', 'Realokowane sektory', RuleOperator::Gt, 0, 50, 1, 0),
            self::metric('smart.*.pending', 'Sektory oczekujące', RuleOperator::Gt, 0, 10, 1, 0),
            self::metric('smart.*.percentage_used', 'Zużycie SSD/NVMe', RuleOperator::Gt, 80, 95, 1, 0),
            self::metric('raid.*.degraded', 'RAID zdegradowany', RuleOperator::Gt, null, 0, 1, 0),
            self::metric('rpi.throttled_now', 'Raspberry Pi: zasilanie/dławienie', RuleOperator::Ne, 0, null, 2, 0),
            self::metric('check.backup.age_s', 'Wiek backupu', RuleOperator::Gt, 93600, 180000, 1, 0),
            [
                'source' => RuleSource::Check,
                'target' => '*',
                'label' => 'Check',
                'operator' => RuleOperator::Gt,
                'warn' => null,
                'crit' => null,
                'consecutive' => 1,
                'hysteresis' => 0.0,
            ],
        ];
    }

    /**
     * @return list<AlertRule>
     */
    public static function createFor(Device $device): array
    {
        $rules = [];
        foreach (self::templates() as $template) {
            $rule = new AlertRule($device, $template['source'], $template['target']);
            $rule->setLabel($template['label']);
            $rule->setOperator($template['operator']);
            $rule->setWarnThreshold($template['warn']);
            $rule->setCritThreshold($template['crit']);
            $rule->setConsecutiveSamples($template['consecutive']);
            $rule->setHysteresis($template['hysteresis']);
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * @return array{source: RuleSource, target: string, label: string, operator: RuleOperator, warn: float|null, crit: float|null, consecutive: int, hysteresis: float}
     */
    private static function metric(string $target, string $label, RuleOperator $operator, int|float|null $warn, int|float|null $crit, int $consecutive, int|float $hysteresis): array
    {
        return [
            'source' => RuleSource::Metric,
            'target' => $target,
            'label' => $label,
            'operator' => $operator,
            'warn' => null === $warn ? null : (float) $warn,
            'crit' => null === $crit ? null : (float) $crit,
            'consecutive' => $consecutive,
            'hysteresis' => (float) $hysteresis,
        ];
    }
}
