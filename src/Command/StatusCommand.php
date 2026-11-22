<?php

declare(strict_types=1);

namespace App\Command;

use App\Admin\DashboardBuilder;
use App\Enum\OutboxStatus;
use App\Repository\DeviceRepository;
use App\Repository\OutboxMessageRepository;
use App\Twig\Formatter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Krótki stan aplikacji (po wdrożeniu, przez SSH): cron, urządzenia, kolejka powiadomień.
 */
#[AsCommand(name: 'app:status', description: 'Stan: ostatni app:tick, urządzenia, kolejka powiadomień')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly DashboardBuilder $dashboard,
        private readonly DeviceRepository $devices,
        private readonly OutboxMessageRepository $outbox,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $cron = $this->dashboard->cronStatus();

        if (null === $cron['last_tick_at']) {
            $io->warning('app:tick jeszcze się nie wykonał – skonfiguruj cron (co minutę).');
        } elseif ($cron['stale']) {
            $io->warning(\sprintf('Ostatni app:tick %s – cron nie działa?', Formatter::ago($cron['last_tick_at'], $now)));
        } else {
            $io->writeln(\sprintf('Cron: OK (ostatni app:tick %s)', Formatter::ago($cron['last_tick_at'], $now)));
        }

        $counts = [];
        foreach ($this->devices->findAll() as $device) {
            $label = $device->getStatus()->label();
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        $io->writeln(\sprintf('Urządzenia: %s', [] === $counts
            ? 'brak'
            : implode(', ', array_map(static fn (string $l, int $c): string => $c.' × '.$l, array_keys($counts), $counts))));

        $pending = $this->outbox->count(['status' => OutboxStatus::Pending]);
        $failed = $this->outbox->count(['status' => OutboxStatus::Failed]);
        $io->writeln(\sprintf('Powiadomienia: w kolejce %d, porzucone %d', $pending, $failed));

        return Command::SUCCESS;
    }
}
