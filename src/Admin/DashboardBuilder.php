<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\AlertState;
use App\Entity\Device;
use App\Enum\Severity;
use App\Metrics\ChartQuery;
use App\Metrics\ChartRange;
use App\Metrics\MetricUnit;
use App\Metrics\Sparkline;
use App\Repository\AlertStateRepository;
use App\Repository\DeviceRepository;
use App\Repository\MetricRepository;
use App\Settings\Settings;
use Psr\Clock\ClockInterface;

/**
 * Składa dane dashboardu: karty urządzeń (wskaźniki, problemy, miniwykres) i stan crona.
 */
final readonly class DashboardBuilder
{
    /** Banner awarii crona, gdy ostatni tick jest starszy niż tyle sekund. */
    public const int CRON_STALE_SECONDS = 180;

    /** Metryki miniwykresu w kolejności preferencji. */
    private const array SPARKLINE_METRICS = [
        'cpu.temp_c' => 'Temperatura CPU, 24 h',
        'load.1' => 'Load, 24 h',
        'disk./.used_pct' => 'Dysk /, 24 h',
    ];

    public function __construct(
        private DeviceRepository $devices,
        private AlertStateRepository $states,
        private MetricRepository $metrics,
        private ChartQuery $charts,
        private Settings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<DeviceCard>
     */
    public function cards(): array
    {
        $now = $this->clock->now();

        return array_map(fn (Device $d): DeviceCard => $this->card($d, $now), $this->devices->findForDashboard());
    }

    /**
     * @return array{stale: bool, last_tick_at: \DateTimeImmutable|null}
     */
    public function cronStatus(): array
    {
        $last = $this->settings->lastTickAt();
        $stale = null === $last || $this->clock->now()->getTimestamp() - $last->getTimestamp() > self::CRON_STALE_SECONDS;

        return ['stale' => $stale, 'last_tick_at' => $last];
    }

    private function card(Device $device, \DateTimeImmutable $now): DeviceCard
    {
        $metrics = $device->getLastSnapshot()['metrics'] ?? [];
        $metrics = \is_array($metrics) ? $metrics : [];

        $states = $this->states->findBy(['device' => $device]);
        $problems = [];
        foreach ($states as $state) {
            $rule = $state->getRule();
            if (Severity::Ok !== $state->getSeverity() && (null === $rule || $rule->isEnabled())) {
                $problems[] = \sprintf('%s: %s', $state->getSeverity()->label(), $state->getTitle());
            }
        }

        [$path, $label] = $this->sparkline($device, $metrics, $now);

        return new DeviceCard(
            $device,
            $this->indicators($device, $metrics, $states),
            $problems,
            $path,
            $label,
            $device->isMutedAt($now),
        );
    }

    /**
     * Kluczowe wskaźniki: dysk (wybrany w panelu albo najbardziej zajęty), temperatura CPU, load, wiek backupu.
     *
     * @param array<array-key, mixed> $metrics
     * @param list<AlertState>        $states
     *
     * @return list<array{label: string, value: float, unit: string|null, severity: string|null}>
     */
    private function indicators(Device $device, array $metrics, array $states): array
    {
        $severityByValueKey = [];
        foreach ($states as $state) {
            $parts = explode(':', $state->getSubject(), 3);
            $key = $parts[2] ?? $state->getRule()?->getTarget();
            if (null !== $key && Severity::Ok !== $state->getSeverity()) {
                $severityByValueKey[$key] = $state->getSeverity()->value;
            }
        }

        $result = [];
        $disk = $this->dashboardDisk($device, $metrics);
        if (null !== $disk) {
            [$mount, $value, $key] = $disk;
            $result[] = ['label' => $device->getDiskLabel($mount) ?? 'Dysk '.$mount, 'value' => $value, 'unit' => '%', 'severity' => $severityByValueKey[$key] ?? null];
        }

        foreach (['cpu.temp_c' => 'CPU', 'load.1' => 'Load', 'check.backup.age_s' => 'Ostatni backup'] as $key => $label) {
            $value = $metrics[$key] ?? null;
            if (\is_float($value) || \is_int($value)) {
                $result[] = ['label' => $label, 'value' => (float) $value, 'unit' => MetricUnit::guess($key), 'severity' => $severityByValueKey[$key] ?? null];
            }
        }

        return $result;
    }

    /**
     * Dysk karty: wybrany w panelu, jeśli agent go raportuje; inaczej najbardziej zajęty.
     *
     * @param array<array-key, mixed> $metrics
     *
     * @return array{string, float, string}|null punkt montowania, zajętość %, klucz metryki
     */
    private function dashboardDisk(Device $device, array $metrics): ?array
    {
        $chosen = $device->getDashboardDisk();
        if (null !== $chosen) {
            $key = 'disk.'.$chosen.'.used_pct';
            $value = $metrics[$key] ?? null;
            if (\is_float($value) || \is_int($value)) {
                return [$chosen, (float) $value, $key];
            }
        }

        $worst = null;
        foreach ($metrics as $key => $value) {
            if (\is_string($key) && preg_match('/^disk\.(.+)\.used_pct$/', $key, $m) && (\is_float($value) || \is_int($value))) {
                if (null === $worst || $value > $worst[1]) {
                    $worst = [$m[1], (float) $value, $key];
                }
            }
        }

        return $worst;
    }

    /**
     * @param array<array-key, mixed> $metrics
     *
     * @return array{string|null, string|null}
     */
    private function sparkline(Device $device, array $metrics, \DateTimeImmutable $now): array
    {
        $deviceId = $device->getId();
        if (null === $deviceId) {
            return [null, null];
        }

        foreach (self::SPARKLINE_METRICS as $key => $label) {
            if (!\array_key_exists($key, $metrics)) {
                continue;
            }
            $metric = $this->metrics->findOneByKey($key);
            $metricId = $metric?->getId();
            if (null === $metricId) {
                continue;
            }

            $points = $this->charts->series($deviceId, $metricId, ChartRange::Day, $now);

            return [Sparkline::path($points, $now->getTimestamp() - ChartRange::Day->seconds(), $now->getTimestamp()), $label];
        }

        return [null, null];
    }
}
