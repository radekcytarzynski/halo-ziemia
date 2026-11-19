<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingest;

use App\Enum\CheckStatus;
use App\Ingest\Payload\HeartbeatParser;
use App\Ingest\Payload\PayloadValidationException;
use App\Metrics\MetricUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeartbeatParserTest extends TestCase
{
    private const string NOW = '2026-11-19 12:00:00';

    public function testFullExamplePayloadIsFlattened(): void
    {
        $result = $this->parse(self::examplePayload());

        self::assertSame([
            'check.backup.age_s' => 5400.0,
            'cpu.temp_c' => 48.5,
            'disk./.free_b' => 300000000000.0,
            'disk./.inodes_used_pct' => 4.2,
            'disk./.used_pct' => 40.0,
            'load.1' => 0.12,
            'load.15' => 0.18,
            'load.5' => 0.2,
            'mem.available_kb' => 5000000.0,
            'mem.available_pct' => 62.5,
            'mem.swap_used_kb' => 0.0,
            'raid.md0.degraded' => 0.0,
            'rpi.throttled' => 0.0,
            'smart.sda.passed' => 1.0,
            'smart.sda.pending' => 0.0,
            'smart.sda.power_on_hours' => 12000.0,
            'smart.sda.reallocated' => 0.0,
            'smart.sda.temp_c' => 36.0,
            'uptime_s' => 123456.0,
        ], $result['metrics']);

        self::assertSame('1.0.0', $result['agent_version']);
        self::assertSame('nas', $result['hostname']);
        self::assertCount(1, $result['checks']);
        self::assertSame('backup', $result['checks'][0]['name']);
        self::assertSame(CheckStatus::Ok, $result['checks'][0]['status']);
        self::assertSame(5400, $result['checks'][0]['age']);

        $snapshot = $result['snapshot'];
        self::assertSame('2026-11-19T12:00:00+00:00', $snapshot['received_at']);
        self::assertSame([['name' => 'md0', 'state' => 'clean', 'degraded' => false]], $snapshot['raid']);
        self::assertSame(['throttled' => '0x0', 'flags' => []], $snapshot['rpi']);
        self::assertArrayNotHasKey('smart.sda.percentage_used', $result['metrics'], 'null jest pomijany');
    }

    public function testMinimalPayloadHasNoMetrics(): void
    {
        $result = $this->parse(['schema' => 1]);

        self::assertSame([], $result['metrics']);
        self::assertSame([], $result['checks']);
        self::assertNull($result['agent_version']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, float> $expectedMetrics
     */
    #[DataProvider('flatteningProvider')]
    public function testFlattening(array $payload, array $expectedMetrics): void
    {
        $metrics = $this->parse(['schema' => 1] + $payload)['metrics'];

        foreach ($expectedMetrics as $key => $value) {
            self::assertArrayHasKey($key, $metrics);
            self::assertEqualsWithDelta($value, $metrics[$key], 0.001, $key);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, float>}>
     */
    public static function flatteningProvider(): iterable
    {
        yield 'swap procentowo' => [
            ['mem' => ['total_kb' => 1000, 'available_kb' => 250, 'swap_total_kb' => 400, 'swap_used_kb' => 100]],
            ['mem.available_pct' => 25.0, 'mem.swap_used_pct' => 25.0],
        ];
        yield 'kilka dysków, ścieżka z ukośnikami' => [
            ['disks' => [
                ['mount' => '/', 'size_b' => 100, 'used_b' => 91],
                ['mount' => '/mnt/backup', 'size_b' => 200, 'used_b' => 50, 'inodes_used_pct' => 1],
            ]],
            ['disk./.used_pct' => 91.0, 'disk./mnt/backup.used_pct' => 25.0, 'disk./mnt/backup.inodes_used_pct' => 1.0, 'disk./mnt/backup.free_b' => 150.0],
        ];
        yield 'SMART nvme + failed' => [
            ['smart' => [['device' => '/dev/nvme0', 'passed' => false, 'percentage_used' => 7]]],
            ['smart.nvme0.passed' => 0.0, 'smart.nvme0.percentage_used' => 7.0],
        ];
        yield 'RAID zdegradowany' => [
            ['raid' => [['name' => 'md1', 'state' => 'degraded', 'degraded' => true]]],
            ['raid.md1.degraded' => 1.0],
        ];
        yield 'throttled RPi' => [
            ['rpi' => ['throttled' => '0x50005']],
            ['rpi.throttled' => 327685.0],
        ];
        yield 'load tylko 1 min' => [
            ['load' => [1.5]],
            ['load.1' => 1.5],
        ];
        yield 'własne metryki' => [
            ['metrics' => ['ups.battery_pct' => 97, 'uptime_s' => 1]],
            ['ups.battery_pct' => 97.0],
        ];
    }

    public function testCustomMetricsDoNotOverrideBuiltIns(): void
    {
        $metrics = $this->parse(['schema' => 1, 'uptime_s' => 100, 'metrics' => ['uptime_s' => 1]])['metrics'];

        self::assertSame(100.0, $metrics['uptime_s']);
    }

    public function testZeroSizedDiskProducesNoPercentage(): void
    {
        $metrics = $this->parse(['schema' => 1, 'disks' => [['mount' => '/boot', 'size_b' => 0, 'used_b' => 0]]])['metrics'];

        self::assertArrayNotHasKey('disk./boot.used_pct', $metrics);
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $result = $this->parse(['schema' => 1, 'future_section' => ['x' => 1], 'timestamp' => 'agent-clock']);

        self::assertSame([], $result['metrics']);
    }

    public function testLongCheckMessageIsTruncated(): void
    {
        $result = $this->parse(['schema' => 1, 'checks' => [['name' => 'backup', 'status' => 'warn', 'message' => str_repeat('x', 600)]]]);

        self::assertSame(500, mb_strlen($result['checks'][0]['message'] ?? ''));
    }

    /**
     * HeartbeatParser::parse() przyjmuje mixed (wynik json_decode), więc data provider też podaje mixed.
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidPayloadIsRejected(mixed $payload, string $expectedError): void
    {
        try {
            (new HeartbeatParser())->parse($payload, new \DateTimeImmutable(self::NOW));
            self::fail('Oczekiwano PayloadValidationException.');
        } catch (PayloadValidationException $e) {
            self::assertStringContainsString($expectedError, implode("\n", $e->getErrors()));
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'napis zamiast obiektu' => ['hello', '$: oczekiwano obiektu JSON'];
        yield 'lista zamiast obiektu' => [[1, 2], '$: oczekiwano obiektu JSON'];
        yield 'liczba' => [42, '$: oczekiwano obiektu JSON'];
        yield 'brak schema' => [['hostname' => 'x'], 'schema: wymagana wartość 1'];
        yield 'schema jako napis' => [['schema' => '1'], 'schema: wymagana wartość 1'];
        yield 'nieznana wersja schema' => [['schema' => 2], 'schema: wymagana wartość 1'];
        yield 'uptime napisem' => [['schema' => 1, 'uptime_s' => '123'], 'uptime_s: oczekiwano liczby'];
        yield 'ujemny uptime' => [['schema' => 1, 'uptime_s' => -1], 'uptime_s: wartość poza zakresem'];
        yield 'nierealna temperatura' => [['schema' => 1, 'cpu_temp_c' => 900], 'cpu_temp_c: wartość poza zakresem'];
        yield 'load jako obiekt' => [['schema' => 1, 'load' => ['a' => 1]], 'load: oczekiwano tablicy'];
        yield 'load za długi' => [['schema' => 1, 'load' => [1, 2, 3, 4]], 'load: maksymalnie 3 elementów'];
        yield 'mem jako lista' => [['schema' => 1, 'mem' => [1]], 'mem: oczekiwano obiektu'];
        yield 'mount względny' => [['schema' => 1, 'disks' => [['mount' => 'data']]], 'disks[0].mount'];
        yield 'mount ze spacją' => [['schema' => 1, 'disks' => [['mount' => '/mnt/my disk']]], 'disks[0].mount'];
        yield 'duplikat mount' => [['schema' => 1, 'disks' => [['mount' => '/'], ['mount' => '/']]], 'disks[1].mount: zduplikowany'];
        yield 'dysk nie-obiekt' => [['schema' => 1, 'disks' => ['/']], 'disks[0]: oczekiwano obiektu'];
        yield 'za dużo dysków' => [['schema' => 1, 'disks' => array_fill(0, 65, ['mount' => '/'])], 'disks: maksymalnie 64'];
        yield 'smart bez device' => [['schema' => 1, 'smart' => [['passed' => true]]], 'smart[0].device'];
        yield 'smart passed napisem' => [['schema' => 1, 'smart' => [['device' => '/dev/sda', 'passed' => 'PASSED']]], 'smart[0].passed: oczekiwano wartości logicznej'];
        yield 'raid zła nazwa' => [['schema' => 1, 'raid' => [['name' => '../etc']]], 'raid[0].name'];
        yield 'throttled nie-hex' => [['schema' => 1, 'rpi' => ['throttled' => 'zero']], 'rpi.throttled'];
        yield 'check zły status' => [['schema' => 1, 'checks' => [['name' => 'backup', 'status' => 'ERROR']]], 'checks[0].status'];
        yield 'check zła nazwa' => [['schema' => 1, 'checks' => [['name' => 'Backup!', 'status' => 'ok']]], 'checks[0].name'];
        yield 'check duplikat' => [['schema' => 1, 'checks' => [['name' => 'a', 'status' => 'ok'], ['name' => 'a', 'status' => 'ok']]], 'checks[1].name: zduplikowana'];
        yield 'własna metryka zły klucz' => [['schema' => 1, 'metrics' => ['Zły Klucz' => 1]], 'metrics.Zły Klucz'];
        yield 'własna metryka napis' => [['schema' => 1, 'metrics' => ['ups.load' => 'high']], 'metrics.ups.load: oczekiwano liczby'];
        yield 'ip liczba' => [['schema' => 1, 'ip' => 1234], 'ip: oczekiwano'];
        yield 'za długa wersja agenta' => [['schema' => 1, 'agent_version' => str_repeat('1', 33)], 'agent_version: maksymalnie 32'];
    }

    public function testAllErrorsAreCollected(): void
    {
        try {
            (new HeartbeatParser())->parse(['schema' => 1, 'uptime_s' => 'x', 'load' => 'y'], new \DateTimeImmutable(self::NOW));
            self::fail('Oczekiwano PayloadValidationException.');
        } catch (PayloadValidationException $e) {
            self::assertCount(2, $e->getErrors());
        }
    }

    #[DataProvider('unitProvider')]
    public function testMetricUnitGuess(string $key, ?string $unit): void
    {
        self::assertSame($unit, MetricUnit::guess($key));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function unitProvider(): iterable
    {
        yield 'procent' => ['disk./.used_pct', '%'];
        yield 'temperatura' => ['smart.sda.temp_c', '°C'];
        yield 'bajty' => ['disk./.free_b', 'B'];
        yield 'sekundy' => ['check.backup.age_s', 's'];
        yield 'godziny' => ['smart.sda.power_on_hours', 'h'];
        yield 'bez jednostki' => ['load.1', null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function examplePayload(): array
    {
        return [
            'schema' => 1,
            'agent_version' => '1.0.0',
            'hostname' => 'nas',
            'uptime_s' => 123456,
            'load' => [0.12, 0.20, 0.18],
            'mem' => ['total_kb' => 8000000, 'available_kb' => 5000000, 'swap_used_kb' => 0],
            'cpu_temp_c' => 48.5,
            'disks' => [
                ['mount' => '/', 'device' => '/dev/sda1', 'size_b' => 500000000000, 'used_b' => 200000000000, 'inodes_used_pct' => 4.2],
            ],
            'smart' => [
                ['device' => '/dev/sda', 'passed' => true, 'temp_c' => 36, 'power_on_hours' => 12000, 'reallocated' => 0, 'pending' => 0, 'percentage_used' => null],
            ],
            'raid' => [['name' => 'md0', 'state' => 'clean', 'degraded' => false]],
            'rpi' => ['throttled' => '0x0'],
            'checks' => [
                ['name' => 'backup', 'status' => 'ok', 'message' => 'rsync OK', 'last_success_age_s' => 5400],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{metrics: array<string, float>, agent_version: ?string, hostname: ?string, snapshot: array<string, mixed>, checks: list<array{name: string, status: CheckStatus, message: ?string, age: ?int}>}
     */
    private function parse(array $payload): array
    {
        $parsed = (new HeartbeatParser())->parse($payload, new \DateTimeImmutable(self::NOW));

        return [
            'metrics' => $parsed->metrics,
            'agent_version' => $parsed->agentVersion,
            'hostname' => $parsed->hostname,
            'snapshot' => $parsed->snapshot,
            'checks' => array_map(static fn ($c): array => ['name' => $c->name, 'status' => $c->status, 'message' => $c->message, 'age' => $c->lastSuccessAgeSeconds], $parsed->checks),
        ];
    }
}
