<?php

declare(strict_types=1);

namespace App\Ingest\Payload;

use App\Enum\CheckStatus;

/**
 * Walidacja payloadu agenta (schema 1) i spłaszczenie do kluczy metryk numerycznych.
 *
 * Zasady:
 *  - nieznane pola są ignorowane (kompatybilność w przód), znane pola muszą mieć poprawny typ,
 *  - agent może pominąć dowolną sekcję poza "schema",
 *  - wartości nienumeryczne (SMART passed, RAID degraded, throttled) są mapowane na liczby,
 *    a ich czytelna postać trafia do snapshotu.
 *
 * Czysta logika: brak zależności od HTTP i bazy.
 */
final class HeartbeatParser
{
    public const int SCHEMA_VERSION = 1;
    public const int MAX_DISKS = 64;
    public const int MAX_SMART = 64;
    public const int MAX_RAID = 32;
    public const int MAX_CHECKS = 32;
    public const int MAX_CUSTOM_METRICS = 100;

    private const string NAME_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/D';
    private const string CHECK_NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/D';
    private const string CUSTOM_KEY_PATTERN = '/^[a-z0-9][a-z0-9_.\/-]{0,99}$/D';
    private const string MOUNT_PATTERN = '/^\/[^\s\x00-\x1f\x7f]{0,150}$/D';

    /** @var list<string> */
    private array $errors = [];

    public function parse(mixed $data, \DateTimeImmutable $receivedAt): ParsedHeartbeat
    {
        $this->errors = [];

        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw new PayloadValidationException(['$: oczekiwano obiektu JSON']);
        }

        $schema = $data['schema'] ?? null;
        if (self::SCHEMA_VERSION !== $schema) {
            throw new PayloadValidationException([\sprintf('schema: wymagana wartość %d', self::SCHEMA_VERSION)]);
        }

        $metrics = [];
        $snapshot = [
            'received_at' => $receivedAt->format(\DATE_ATOM),
        ];

        $agentVersion = $this->string($data, 'agent_version', 'agent_version', 32);
        $hostname = $this->string($data, 'hostname', 'hostname', 255);
        $snapshot['agent_version'] = $agentVersion;
        $snapshot['hostname'] = $hostname;
        $snapshot['ip'] = $this->ip($data);

        $this->setMetric($metrics, 'uptime_s', $this->number($data, 'uptime_s', 'uptime_s', min: 0));
        $this->setMetric($metrics, 'cpu.temp_c', $this->number($data, 'cpu_temp_c', 'cpu_temp_c', min: -100, max: 200));
        $this->parseCpu($data, $metrics);
        $this->parseLoad($data, $metrics);
        $this->parseMem($data, $metrics);
        $snapshot['disks'] = $this->parseDisks($data, $metrics);
        $snapshot['smart'] = $this->parseSmart($data, $metrics);
        $snapshot['raid'] = $this->parseRaid($data, $metrics);
        $snapshot['rpi'] = $this->parseRpi($data, $metrics);
        $checks = $this->parseChecks($data, $metrics);
        $this->parseCustomMetrics($data, $metrics);

        if ([] !== $this->errors) {
            throw new PayloadValidationException($this->errors);
        }

        ksort($metrics);
        $snapshot['metrics'] = $metrics;
        $snapshot['checks'] = array_map(static fn (CheckReport $c): array => [
            'name' => $c->name,
            'status' => $c->status->value,
            'message' => $c->message,
            'last_success_age_s' => $c->lastSuccessAgeSeconds,
        ], $checks);

        return new ParsedHeartbeat($agentVersion, $hostname, $metrics, $checks, $snapshot);
    }

    /**
     * cpu: {"used_pct", "iowait_pct"} – średnie od poprzedniego heartbeatu.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     */
    private function parseCpu(array $data, array &$metrics): void
    {
        $cpu = $this->object($data, 'cpu', 'cpu');
        if (null === $cpu) {
            return;
        }

        $this->setMetric($metrics, 'cpu.used_pct', $this->number($cpu, 'used_pct', 'cpu.used_pct', min: 0, max: 100));
        $this->setMetric($metrics, 'cpu.iowait_pct', $this->number($cpu, 'iowait_pct', 'cpu.iowait_pct', min: 0, max: 100));
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     */
    private function parseLoad(array $data, array &$metrics): void
    {
        $load = $this->list($data, 'load', 'load', 3);
        if (null === $load) {
            return;
        }

        foreach (['load.1', 'load.5', 'load.15'] as $i => $key) {
            if (\array_key_exists($i, $load)) {
                $this->setMetric($metrics, $key, $this->number($load, $i, \sprintf('load[%d]', $i), min: 0));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     */
    private function parseMem(array $data, array &$metrics): void
    {
        $mem = $this->object($data, 'mem', 'mem');
        if (null === $mem) {
            return;
        }

        $total = $this->number($mem, 'total_kb', 'mem.total_kb', min: 0);
        $available = $this->number($mem, 'available_kb', 'mem.available_kb', min: 0);
        $swapTotal = $this->number($mem, 'swap_total_kb', 'mem.swap_total_kb', min: 0);
        $swapUsed = $this->number($mem, 'swap_used_kb', 'mem.swap_used_kb', min: 0);

        $this->setMetric($metrics, 'mem.available_kb', $available);
        $this->setMetric($metrics, 'mem.swap_used_kb', $swapUsed);
        if (null !== $total && null !== $available && $total > 0) {
            $this->setMetric($metrics, 'mem.available_pct', self::pct($available, $total));
        }
        if (null !== $swapTotal && null !== $swapUsed && $swapTotal > 0) {
            $this->setMetric($metrics, 'mem.swap_used_pct', self::pct($swapUsed, $swapTotal));
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     *
     * @return list<array{mount: string, device: string|null, size_b: float|null, used_b: float|null}>
     */
    private function parseDisks(array $data, array &$metrics): array
    {
        $disks = $this->list($data, 'disks', 'disks', self::MAX_DISKS);
        $snapshot = [];
        $seen = [];

        foreach ($disks ?? [] as $i => $disk) {
            $path = \sprintf('disks[%d]', $i);
            if (!\is_array($disk)) {
                $this->errors[] = $path.': oczekiwano obiektu';
                continue;
            }

            $mount = $this->string($disk, 'mount', $path.'.mount', 151);
            if (null === $mount || 1 !== preg_match(self::MOUNT_PATTERN, $mount)) {
                $this->errors[] = $path.'.mount: wymagana ścieżka absolutna bez białych znaków';
                continue;
            }
            if (isset($seen[$mount])) {
                $this->errors[] = $path.'.mount: zduplikowany punkt montowania';
                continue;
            }
            $seen[$mount] = true;

            $size = $this->number($disk, 'size_b', $path.'.size_b', min: 0);
            $used = $this->number($disk, 'used_b', $path.'.used_b', min: 0);
            $inodes = $this->number($disk, 'inodes_used_pct', $path.'.inodes_used_pct', min: 0, max: 100);
            $device = $this->string($disk, 'device', $path.'.device', 255);

            $prefix = 'disk.'.$mount.'.';
            if (null !== $size && null !== $used && $size > 0) {
                $this->setMetric($metrics, $prefix.'used_pct', self::pct($used, $size));
                $this->setMetric($metrics, $prefix.'free_b', max(0.0, $size - $used));
            }
            $this->setMetric($metrics, $prefix.'inodes_used_pct', $inodes);

            $snapshot[] = ['mount' => $mount, 'device' => $device, 'size_b' => $size, 'used_b' => $used];
        }

        return $snapshot;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     *
     * @return list<array{name: string, passed: bool|null}>
     */
    private function parseSmart(array $data, array &$metrics): array
    {
        $entries = $this->list($data, 'smart', 'smart', self::MAX_SMART);
        $snapshot = [];

        foreach ($entries ?? [] as $i => $entry) {
            $path = \sprintf('smart[%d]', $i);
            if (!\is_array($entry)) {
                $this->errors[] = $path.': oczekiwano obiektu';
                continue;
            }

            $device = $this->string($entry, 'device', $path.'.device', 255);
            $name = null === $device ? null : basename($device);
            if (null === $name || 1 !== preg_match(self::NAME_PATTERN, $name)) {
                $this->errors[] = $path.'.device: wymagana nazwa urządzenia (np. /dev/sda)';
                continue;
            }

            $prefix = 'smart.'.$name.'.';
            $passed = $this->bool($entry, 'passed', $path.'.passed');
            if (null !== $passed) {
                $metrics[$prefix.'passed'] = $passed ? 1.0 : 0.0;
            }
            $this->setMetric($metrics, $prefix.'temp_c', $this->number($entry, 'temp_c', $path.'.temp_c', min: -50, max: 150));
            foreach (['power_on_hours', 'reallocated', 'pending', 'percentage_used'] as $field) {
                $this->setMetric($metrics, $prefix.$field, $this->number($entry, $field, $path.'.'.$field, min: 0));
            }

            $snapshot[] = ['name' => $name, 'passed' => $passed];
        }

        return $snapshot;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     *
     * @return list<array{name: string, state: string|null, degraded: bool|null}>
     */
    private function parseRaid(array $data, array &$metrics): array
    {
        $entries = $this->list($data, 'raid', 'raid', self::MAX_RAID);
        $snapshot = [];

        foreach ($entries ?? [] as $i => $entry) {
            $path = \sprintf('raid[%d]', $i);
            if (!\is_array($entry)) {
                $this->errors[] = $path.': oczekiwano obiektu';
                continue;
            }

            $name = $this->string($entry, 'name', $path.'.name', 32);
            if (null === $name || 1 !== preg_match(self::NAME_PATTERN, $name)) {
                $this->errors[] = $path.'.name: wymagana nazwa macierzy (np. md0)';
                continue;
            }

            $state = $this->string($entry, 'state', $path.'.state', 32);
            $degraded = $this->bool($entry, 'degraded', $path.'.degraded');
            if (null !== $degraded) {
                $metrics['raid.'.$name.'.degraded'] = $degraded ? 1.0 : 0.0;
            }

            $snapshot[] = ['name' => $name, 'state' => $state, 'degraded' => $degraded];
        }

        return $snapshot;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     *
     * @return array{throttled: string, flags: list<string>}|null
     */
    private function parseRpi(array $data, array &$metrics): ?array
    {
        $rpi = $this->object($data, 'rpi', 'rpi');
        if (null === $rpi) {
            return null;
        }

        $raw = $this->string($rpi, 'throttled', 'rpi.throttled', 10);
        if (null === $raw) {
            return null;
        }

        $mask = RpiThrottled::parse($raw);
        if (null === $mask) {
            $this->errors[] = 'rpi.throttled: oczekiwano wartości szesnastkowej, np. "0x0"';

            return null;
        }

        $metrics['rpi.throttled'] = (float) $mask;
        // Bity 0–3 = stan bieżący (bity 16–19 to historia od startu, zostają ustawione do restartu).
        $metrics['rpi.throttled_now'] = (float) ($mask & RpiThrottled::NOW_MASK);

        return ['throttled' => $raw, 'flags' => RpiThrottled::describe($mask)];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     *
     * @return list<CheckReport>
     */
    private function parseChecks(array $data, array &$metrics): array
    {
        $entries = $this->list($data, 'checks', 'checks', self::MAX_CHECKS);
        $checks = [];
        $seen = [];

        foreach ($entries ?? [] as $i => $entry) {
            $path = \sprintf('checks[%d]', $i);
            if (!\is_array($entry)) {
                $this->errors[] = $path.': oczekiwano obiektu';
                continue;
            }

            $name = $this->string($entry, 'name', $path.'.name', 64);
            if (null === $name || 1 !== preg_match(self::CHECK_NAME_PATTERN, $name)) {
                $this->errors[] = $path.'.name: wymagana nazwa [a-z0-9_.-], max 64 znaki';
                continue;
            }
            if (isset($seen[$name])) {
                $this->errors[] = $path.'.name: zduplikowana nazwa checka';
                continue;
            }
            $seen[$name] = true;

            $rawStatus = $this->string($entry, 'status', $path.'.status', 8);
            $status = null === $rawStatus ? null : CheckStatus::tryFrom($rawStatus);
            if (null === $status) {
                $this->errors[] = $path.'.status: wymagane ok|warn|fail';
                continue;
            }

            $message = $this->string($entry, 'message', $path.'.message', 500, truncate: true);
            $age = $this->number($entry, 'last_success_age_s', $path.'.last_success_age_s', min: 0);
            $ageSeconds = null === $age ? null : (int) $age;

            if (null !== $ageSeconds) {
                $metrics['check.'.$name.'.age_s'] = (float) $ageSeconds;
            }

            $checks[] = new CheckReport($name, $status, $message, $ageSeconds);
        }

        return $checks;
    }

    /**
     * Sekcja "metrics": własne metryki z pluginów agenta. Nie nadpisują metryk wbudowanych.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, float>    $metrics
     */
    private function parseCustomMetrics(array $data, array &$metrics): void
    {
        $custom = $this->object($data, 'metrics', 'metrics');
        if (null === $custom) {
            return;
        }
        if (\count($custom) > self::MAX_CUSTOM_METRICS) {
            $this->errors[] = \sprintf('metrics: maksymalnie %d kluczy', self::MAX_CUSTOM_METRICS);

            return;
        }

        foreach ($custom as $key => $_) {
            $key = (string) $key;
            if (1 !== preg_match(self::CUSTOM_KEY_PATTERN, $key)) {
                $this->errors[] = \sprintf('metrics.%s: niedozwolony klucz (dozwolone [a-z0-9_./-])', $key);
                continue;
            }
            $value = $this->number($custom, $key, 'metrics.'.$key);
            if (null !== $value && !\array_key_exists($key, $metrics)) {
                $metrics[$key] = $value;
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>|string|null
     */
    private function ip(array $data): array|string|null
    {
        $ip = $data['ip'] ?? null;
        if (null === $ip) {
            return null;
        }
        if (\is_string($ip)) {
            return mb_substr($ip, 0, 200);
        }
        if (\is_array($ip) && array_is_list($ip) && \count($ip) <= 16) {
            $result = [];
            foreach ($ip as $item) {
                if (!\is_string($item)) {
                    $this->errors[] = 'ip: oczekiwano listy napisów';

                    return null;
                }
                $result[] = mb_substr($item, 0, 64);
            }

            return $result;
        }

        $this->errors[] = 'ip: oczekiwano napisu lub listy napisów (max 16)';

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function number(array $data, int|string $field, string $path, ?float $min = null, ?float $max = null): ?float
    {
        $value = $data[$field] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) && !\is_float($value)) {
            $this->errors[] = $path.': oczekiwano liczby';

            return null;
        }

        $value = (float) $value;
        if (!is_finite($value)) {
            $this->errors[] = $path.': liczba poza zakresem';

            return null;
        }
        if ((null !== $min && $value < $min) || (null !== $max && $value > $max)) {
            $this->errors[] = \sprintf('%s: wartość poza zakresem [%s, %s]', $path, $min ?? '-∞', $max ?? '∞');

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $field, string $path, int $maxLength, bool $truncate = false): ?string
    {
        $value = $data[$field] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            $this->errors[] = $path.': oczekiwano napisu';

            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            if ($truncate) {
                return mb_substr($value, 0, $maxLength);
            }
            $this->errors[] = \sprintf('%s: maksymalnie %d znaków', $path, $maxLength);

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function bool(array $data, string $field, string $path): ?bool
    {
        $value = $data[$field] ?? null;
        if (null === $value || \is_bool($value)) {
            return $value;
        }

        $this->errors[] = $path.': oczekiwano wartości logicznej';

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>|null
     */
    private function list(array $data, string $field, string $path, int $maxItems): ?array
    {
        $value = $data[$field] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_array($value) || !array_is_list($value)) {
            $this->errors[] = $path.': oczekiwano tablicy';

            return null;
        }
        if (\count($value) > $maxItems) {
            $this->errors[] = \sprintf('%s: maksymalnie %d elementów', $path, $maxItems);

            return null;
        }

        return $value;
    }

    /**
     * Obiekt JSON (pusty obiekt "{}" dekoduje się do pustej tablicy – akceptujemy).
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>|null
     */
    private function object(array $data, string $field, string $path): ?array
    {
        $value = $data[$field] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            $this->errors[] = $path.': oczekiwano obiektu';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, float> $metrics
     */
    private function setMetric(array &$metrics, string $key, ?float $value): void
    {
        if (null !== $value) {
            $metrics[$key] = $value;
        }
    }

    private static function pct(float $part, float $total): float
    {
        return round($part / $total * 100, 2);
    }
}
