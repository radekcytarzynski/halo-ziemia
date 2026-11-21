<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\NotificationChannel;
use App\Notification\NotificationFailedException;
use App\Notification\TestNotificationSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:notify:test', description: 'Wysyła testowy e-mail (sprawdzenie MAILER_DSN)')]
final class NotifyTestCommand extends Command
{
    public function __construct(private readonly TestNotificationSender $sender)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adres odbiorcy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        try {
            $this->sender->send(NotificationChannel::Email, $email);
        } catch (NotificationFailedException $e) {
            $io->error('Wysyłka nie powiodła się: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Wysłano testowy e-mail do %s.', $email));

        return Command::SUCCESS;
    }
}
