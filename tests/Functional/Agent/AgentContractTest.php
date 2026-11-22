<?php

declare(strict_types=1);

namespace App\Tests\Functional\Agent;

use App\Ingest\Payload\HeartbeatParser;
use PHPUnit\Framework\TestCase;

/**
 * Kontrakt agent ↔ serwer: payload wygenerowany przez prawdziwy skrypt agenta
 * (na fixture'ach z agent/tests) musi przejść walidację serwera.
 */
final class AgentContractTest extends TestCase
{
    public function testAgentPayloadIsAcceptedByServerParser(): void
    {
        if (null === shell_exec('command -v bash jq 2>/dev/null')) {
            self::markTestSkipped('Brak bash/jq.');
        }

        $agent = \dirname(__DIR__, 3).'/agent';
        $state = sys_get_temp_dir().'/hz-state-'.bin2hex(random_bytes(4));
        $checks = $state.'/checks.d';
        mkdir($checks, 0o700, true);
        copy($agent.'/checks.d/backup.sh.example', $checks.'/backup.sh');
        chmod($checks.'/backup.sh', 0o700);
        touch($state.'/last-success', time() - 3600);

        $env = [
            'PATH' => $agent.'/tests/stubs:'.getenv('PATH'),
            'HZ_PROC' => $agent.'/tests/fixtures/proc',
            'HZ_SYS' => $agent.'/tests/fixtures/sys',
            'HZ_TEST_SMART' => $agent.'/tests/fixtures/smart',
            'HZ_TEST_DF' => $agent.'/tests/fixtures/df.txt',
            'HZ_TEST_THROTTLED' => '0x50005',
            'HZ_STATE_DIR' => $state,
            'HZ_CHECKS_DIR' => $checks,
            'HZ_CONFIG' => '/nonexistent',
            'HZ_LIB_DIR' => $agent.'/lib',
            'SUCCESS_MARKER' => $state.'/last-success',
            'ERROR_FILE' => $state.'/last-error',
        ];
        $command = 'env -i '.implode(' ', array_map(static fn (string $k, string $v): string => $k.'='.escapeshellarg($v), array_keys($env), $env))
            .' bash '.escapeshellarg($agent.'/halo-ziemia-agent').' --dry-run';

        $output = (string) shell_exec($command);
        exec('rm -rf '.escapeshellarg($state));

        $payload = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        $parsed = (new HeartbeatParser())->parse($payload, new \DateTimeImmutable());

        self::assertSame('nas-test', $parsed->hostname);
        self::assertSame(864123.0, $parsed->metrics['uptime_s']);
        self::assertSame(52.3, $parsed->metrics['cpu.temp_c']);
        self::assertArrayHasKey('disk./mnt/backup.used_pct', $parsed->metrics);
        self::assertSame(1.0, $parsed->metrics['smart.sda.passed']);
        self::assertSame(0.0, $parsed->metrics['smart.nvme0.passed']);
        self::assertSame(5.0, $parsed->metrics['rpi.throttled_now']);
        self::assertCount(1, $parsed->checks);
        self::assertSame('backup', $parsed->checks[0]->name);
        self::assertEqualsWithDelta(3600, $parsed->metrics['check.backup.age_s'], 60);
    }
}
