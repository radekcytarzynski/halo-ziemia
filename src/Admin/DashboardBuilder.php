<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\AlertState;
use App\Entity\Device;
use App\Enum\Severity;
use App\Metrics\ChartQuery;
use App\Metrics\ChartRange;
use App\Metrics\KeyMetrics;
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

    /** Metryki miniwykresu w kolejności preferencji, gdy urządzenie nie ma wybranej (Device::getDashboardChart). */
    public const array SPARKLINE_METRICS = ['cpu.used_pct', 'cpu.temp_c', 'load.1', 'disk./.used_pct'];

    /** Wskaźniki automatyczne poza dyskiem, w kolejności na karcie. */
    public const array AUTO_INDICATORS = ['cpu.used_pct', 'cpu.temp_c', 'load.1', 'check.backup.age_s'];

    /** Najwięcej wskaźników wybranych ręcznie. */
    public const int MAX_INDICATORS = 8;

    private const array INDICATOR_LABELS = [
        'cpu.used_pct' => 'CPU',
        'cpu.iowait_pct' => 'CPU iowait',
        'cpu.temp_c' => 'Temp. CPU',
        'load.1' => 'Load',
        'load.5' => 'Load 5 min',
        'load.15' => 'Load 15 min',
        'mem.available_pct' => 'Wolna pamięć',
        'mem.swap_used_pct' => 'Swap',
        'uptime_s' => 'Uptime',
        'check.backup.age_s' => 'Ostatni backup',
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
     * Wskaźniki karty: wybrane w panelu (Device::getDashboardIndicators), a gdy brak wyboru albo żadna
     * z wybranych metryk nie jest już raportowana – automatyczne (autoIndicatorKeys).
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

        $keys = array_values(array_filter($device->getDashboardIndicators(), static fn (string $k): bool => \array_key_exists($k, $metrics)));
        if ([] === $keys) {
            $keys = self::autoIndicatorKeys($device, $metrics);
        }

        $result = [];
        foreach ($keys as $key) {
            $value = $metrics[$key] ?? null;
            if (\is_float($value) || \is_int($value)) {
                $result[] = ['label' => self::indicatorLabel($device, $key), 'value' => (float) $value, 'unit' => MetricUnit::guess($key), 'severity' => $severityByValueKey[$key] ?? null];
            }
        }

        return $result;
    }

    /**
     * Wskaźniki automatyczne: dysk (wybrany w panelu albo najbardziej zajęty) i AUTO_INDICATORS, o ile raportowane.
     *
     * @param array<array-key, mixed> $metrics
     *
     * @return list<string>
     */
    public static function autoIndicatorKeys(Device $device, array $metrics): array
    {
        $keys = [];
        $disk = self::dashboardDisk($device, $metrics);
        if (null !== $disk) {
            $keys[] = $disk[2];
        }
        foreach (self::AUTO_INDICATORS as $key) {
            $value = $metrics[$key] ?? null;
            if (\is_float($value) || \is_int($value)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Krótka etykieta kafelka wskaźnika, np. "CPU", "Backup" (nazwa dysku), "Temp. Lexar".
     */
    public static function indicatorLabel(Device $device, string $key): string
    {
        if (isset(self::INDICATOR_LABELS[$key])) {
            return self::INDICATOR_LABELS[$key];
        }
        if (preg_match('/^disk\.(.+)\.used_pct$/', $key, $m)) {
            return $device->getDiskLabel($m[1]) ?? 'Dysk '.$m[1];
        }
        if (preg_match('/^smart\.(.+)\.temp_c$/', $key, $m)) {
            return 'Temp. '.($device->getDiskLabel($m[1]) ?? $m[1]);
        }

        return KeyMetrics::label($key, $device->getDiskLabels()) ?? $key;
    }

    /**
     * Metryki do wyboru wskaźników w kolejności kart (dyski, CPU, temperatura, load, pamięć, checki), potem reszta.
     *
     * @param list<string> $keys
     *
     * @return array<string, string> klucz => pełna etykieta
     */
    public static function indicatorOptions(Device $device, array $keys): array
    {
        $groups = ['/^disk\..+\.used_pct$/', '/^cpu\.used_pct$/', '/^cpu\.temp_c$/', '/^cpu\./', '/^load\.1$/', '/^load\.5$/', '/^load\./', '/^mem\./', '/^check\./', '/^smart\..+\.temp_c$/'];
        $rank = static function (string $key) use ($groups): int {
            foreach ($groups as $i => $pattern) {
                if (preg_match($pattern, $key)) {
                    return $i;
                }
            }

            return \count($groups);
        };
        usort($keys, static fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        $options = [];
        foreach ($keys as $key) {
            $options[$key] = KeyMetrics::label($key, $device->getDiskLabels()) ?? $key;
        }

        return $options;
    }

    /**
     * Dysk karty: wybrany w panelu, jeśli agent go raportuje; inaczej najbardziej zajęty.
     *
     * @param array<array-key, mixed> $metrics
     *
     * @return array{string, float, string}|null punkt montowania, zajętość %, klucz metryki
     */
    private static function dashboardDisk(Device $device, array $metrics): ?array
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

        $chosen = $device->getDashboardChart();
        $candidates = null !== $chosen && \array_key_exists($chosen, $metrics) ? [$chosen] : self::SPARKLINE_METRICS;

        foreach ($candidates as $key) {
            if (!\array_key_exists($key, $metrics)) {
                continue;
            }
            $metric = $this->metrics->findOneByKey($key);
            $metricId = $metric?->getId();
            if (null === $metricId) {
                continue;
            }

            $points = $this->charts->series($deviceId, $metricId, ChartRange::Day, $now);

            $label = KeyMetrics::label($key, $device->getDiskLabels()) ?? $key;

            return [Sparkline::path($points, $now->getTimestamp() - ChartRange::Day->seconds(), $now->getTimestamp()), $label.', 24 h'];
        }

        return [null, null];
    }
}
