<?php

declare(strict_types=1);

namespace App\Command;

use App\Metrics\HourlyRollup;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Uruchamiane z crona co godzinę (kilka minut po pełnej godzinie):
 *   5 * * * * php /ścieżka/bin/console app:rollup
 */
#[AsCommand(name: 'app:rollup', description: 'Agregacja próbek do rollupów godzinnych (wykresy 30 dni i rok)')]
final class RollupCommand extends Command
{
    private const int LOCK_TTL_SECONDS = 1800;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly HourlyRollup $rollup,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('app-rollup', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->warning('Poprzedni app:rollup nadal działa – pomijam.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->rollup->run($this->clock->now());
            $io->writeln(null === $result->until
                ? 'Brak próbek – nic do agregacji.'
                : \sprintf('Godziny: %d, wiersze: %d, rollup do %s UTC', $result->hours, $result->rows, gmdate('Y-m-d H:i', $result->until)));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->critical('app:rollup nie powiódł się: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
