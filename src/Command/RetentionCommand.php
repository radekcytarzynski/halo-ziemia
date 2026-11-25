<?php

declare(strict_types=1);

namespace App\Command;

use App\Metrics\DataRetention;
use App\Settings\Settings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Uruchamiane z crona raz na dobę:
 *   30 3 * * * php /ścieżka/bin/console app:retention
 */
#[AsCommand(name: 'app:retention', description: 'Usuwanie danych starszych niż ustawienia retencji')]
final class RetentionCommand extends Command
{
    private const int LOCK_TTL_SECONDS = 3600;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly DataRetention $retention,
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Tylko policz, co zostałoby usunięte')
            ->addOption('optimize', null, InputOption::VALUE_NONE, 'Po usunięciu odzyskaj miejsce na dysku (OPTIMIZE TABLE; jednorazowo, przebudowuje tabele)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $lock = $this->lockFactory->createLock('app-retention', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->warning('Poprzedni app:retention nadal działa – pomijam.');

            return Command::SUCCESS;
        }

        try {
            $r = $this->retention->run($this->clock->now(), $dryRun);

            $io->writeln(\sprintf(
                '%s: próbki %d, rollupy %d, zdarzenia %d, incydenty %d, powiadomienia %d',
                $dryRun ? 'Do usunięcia (--dry-run)' : 'Usunięto',
                $r->samples,
                $r->hourly,
                $r->events,
                $r->incidents,
                $r->notifications,
            ));
            $io->writeln(null === $r->rawCutoff
                ? 'Próbki surowe pominięte: app:rollup jeszcze się nie wykonał (próbki muszą najpierw trafić do rollupów).'
                : \sprintf(
                    'Granice: próbki surowe sprzed %s UTC (%d dni), pozostałe sprzed %s UTC (%d dni)',
                    gmdate('Y-m-d H:i', $r->rawCutoff),
                    $this->settings->retentionRawDays(),
                    gmdate('Y-m-d H:i', $r->longCutoff),
                    $this->settings->retentionRollupDays(),
                ));

            if ($input->getOption('optimize') && !$dryRun) {
                $io->writeln('OPTIMIZE TABLE…');
                $this->retention->optimize();
                $io->writeln('Gotowe.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->critical('app:retention nie powiódł się: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
