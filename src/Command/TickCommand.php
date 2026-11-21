<?php

declare(strict_types=1);

namespace App\Command;

use App\Alerting\AlertEngine;
use App\Monitoring\Watchdog;
use App\Notification\OutboxProcessor;
use App\Settings\Settings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Uruchamiane z crona co minutę:
 *   * * * * * php /ścieżka/current/bin/console app:tick
 * Blokada zapobiega nakładaniu się uruchomień.
 */
#[AsCommand(name: 'app:tick', description: 'Ocena braku heartbeatów, przypomnienia, wysyłka powiadomień, watchdog')]
final class TickCommand extends Command
{
    private const int LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly AlertEngine $engine,
        private readonly OutboxProcessor $outbox,
        private readonly Settings $settings,
        private readonly Watchdog $watchdog,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('app-tick', self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            $io->warning('Poprzedni app:tick nadal działa – pomijam.');

            return Command::SUCCESS;
        }

        try {
            $now = $this->clock->now();
            $result = $this->engine->tick($now);
            $outbox = $this->outbox->process($this->clock->now());
            $this->settings->markTick($now);
            $watchdog = $this->watchdog->ping();

            $io->writeln(\sprintf(
                'Urządzenia: %d (DOWN: %d), zakolejkowano: %d, wysłano: %d, ponowienia: %d, porzucone: %d, watchdog: %s',
                $result->devicesEvaluated,
                $result->devicesDown,
                $result->notificationsQueued,
                $outbox->sent,
                $outbox->retrying,
                $outbox->failed,
                $this->watchdog->isConfigured() ? ($watchdog ? 'OK' : 'błąd') : 'wyłączony',
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // Bez pingu watchdoga: zewnętrzny monitor zauważy problem.
            $this->logger->critical('app:tick nie powiódł się: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
