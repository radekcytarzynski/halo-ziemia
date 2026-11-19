<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use App\Tests\Functional\AdminFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCreateCommandTest extends KernelTestCase
{
    use AdminFixtures;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:user:create'));
    }

    public function testCreatesAdministratorWithHashedPassword(): void
    {
        $this->tester->setInputs(['bardzo-tajne-haslo', 'bardzo-tajne-haslo']);

        $exitCode = $this->tester->execute(['email' => ' Admin@Example.TEST ']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $user = $this->findUser('admin@example.test');
        self::assertNotSame('bardzo-tajne-haslo', $user->getPassword());
        self::assertTrue($this->hasher()->isPasswordValid($user, 'bardzo-tajne-haslo'));
    }

    /**
     * @param list<string>         $inputs
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('failureProvider')]
    public function testRejectsInvalidInput(array $arguments, array $inputs, int $expectedExit, string $expectedMessage): void
    {
        $this->createAdmin();
        $this->tester->setInputs($inputs);

        $exitCode = $this->tester->execute($arguments);

        self::assertSame($expectedExit, $exitCode);
        self::assertStringContainsString($expectedMessage, $this->normalizedDisplay());
    }

    /**
     * @return iterable<string, array{array<string, string|bool>, list<string>, int, string}>
     */
    public static function failureProvider(): iterable
    {
        yield 'zły e-mail' => [['email' => 'nie-email'], [], Command::INVALID, 'Nieprawidłowy adres e-mail'];
        yield 'za krótkie hasło' => [['email' => 'nowy@example.test'], ['krotkie'], Command::FAILURE, 'co najmniej 12 znaków'];
        yield 'różne hasła' => [['email' => 'nowy@example.test'], ['bardzo-tajne-haslo', 'inne-tajne-haslo!'], Command::FAILURE, 'nie są identyczne'];
        yield 'duplikat' => [['email' => self::ADMIN_EMAIL], [], Command::FAILURE, 'już istnieje'];
        yield 'reset nieistniejącego' => [['email' => 'brak@example.test', '--reset-password' => true], [], Command::FAILURE, 'Nie ma administratora'];
    }

    public function testResetPassword(): void
    {
        $this->createAdmin();
        $this->tester->setInputs(['nowe-bardzo-tajne', 'nowe-bardzo-tajne']);

        $exitCode = $this->tester->execute(['email' => self::ADMIN_EMAIL, '--reset-password' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->hasher()->isPasswordValid($this->findUser(self::ADMIN_EMAIL), 'nowe-bardzo-tajne'));
    }

    private function findUser(string $email): AdminUser
    {
        $repository = self::getContainer()->get(AdminUserRepository::class);
        $user = $repository->findOneByEmail($email);
        self::assertInstanceOf(AdminUser::class, $user);

        return $user;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher;
    }

    private function normalizedDisplay(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay());
    }
}
