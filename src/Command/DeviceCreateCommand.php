<?php

declare(strict_types=1);

namespace App\Command;

use App\Device\DeviceManager;
use App\Token\DeviceTokenManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pomocnicza (dev): tworzy urządzenie i wypisuje jego token (jedyny raz, kiedy jest widoczny).
 */
#[AsCommand(name: 'app:device:create', description: 'Tworzy urządzenie i wydaje token agenta')]
final class DeviceCreateCommand extends Command
{
    public function __construct(
        private readonly DeviceManager $devices,
        private readonly DeviceTokenManager $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Nazwa urządzenia, np. "NAS Debian"')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Identyfikator (domyślnie z nazwy)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = trim((string) $input->getArgument('name'));
        $slugOption = $input->getOption('slug');
        $slug = \is_string($slugOption) ? $slugOption : $this->devices->suggestSlug($name);

        if ('' === $name || mb_strlen($name) > 100) {
            $io->error('Nazwa jest wymagana (max 100 znaków).');

            return Command::INVALID;
        }
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug)) {
            $io->error('Identyfikator: małe litery, cyfry i myślnik.');

            return Command::INVALID;
        }
        if ($this->devices->slugExists($slug)) {
            $io->error(\sprintf('Urządzenie "%s" już istnieje.', $slug));

            return Command::FAILURE;
        }

        $device = $this->devices->create($name, $slug);
        $issued = $this->tokens->issue($device);

        $io->success(\sprintf('Utworzono urządzenie "%s" (%s).', $device->getName(), $device->getSlug()));
        $io->writeln('Token agenta (pokazywany tylko raz):');
        $io->writeln('  '.$issued->plainText);

        return Command::SUCCESS;
    }
}
