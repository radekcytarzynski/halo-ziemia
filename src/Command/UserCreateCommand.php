<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tworzy administratora (lub zmienia mu hasło z --reset-password).
 * Hasło podawane wyłącznie interaktywnie, aby nie trafiło do historii powłoki ani `ps`.
 */
#[AsCommand(name: 'app:user:create', description: 'Tworzy konto administratora panelu')]
final class UserCreateCommand extends Command
{
    private const int MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail (login)')
            ->addOption('reset-password', null, InputOption::VALUE_NONE, 'Zmień hasło istniejącego administratora');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));

        if (\count($this->validator->validate($email, [new Email(mode: Email::VALIDATION_MODE_STRICT)])) > 0 || '' === $email) {
            $io->error('Nieprawidłowy adres e-mail.');

            return Command::INVALID;
        }

        $reset = (bool) $input->getOption('reset-password');
        $user = $this->users->findOneByEmail($email);

        if (null !== $user && !$reset) {
            $io->error('Administrator o tym adresie już istnieje. Użyj --reset-password, aby zmienić hasło.');

            return Command::FAILURE;
        }
        if (null === $user && $reset) {
            $io->error('Nie ma administratora o tym adresie.');

            return Command::FAILURE;
        }

        $password = $this->askPassword($io);
        if (null === $password) {
            return Command::FAILURE;
        }

        $user ??= new AdminUser($email, $this->clock->now());
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success($reset ? \sprintf('Zmieniono hasło: %s', $email) : \sprintf('Utworzono administratora: %s', $email));

        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io): ?string
    {
        $password = $io->askHidden(\sprintf('Hasło (min. %d znaków)', self::MIN_PASSWORD_LENGTH));
        if (!\is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf('Hasło musi mieć co najmniej %d znaków.', self::MIN_PASSWORD_LENGTH));

            return null;
        }

        $repeat = $io->askHidden('Powtórz hasło');
        if ($repeat !== $password) {
            $io->error('Hasła nie są identyczne.');

            return null;
        }

        return $password;
    }
}
