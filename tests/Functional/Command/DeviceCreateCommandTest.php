<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Repository\DeviceRepository;
use App\Token\DeviceTokenManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeviceCreateCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester((new Application(self::bootKernel()))->find('app:device:create'));
    }

    public function testCreatesDeviceAndPrintsWorkingToken(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute(['name' => 'Raspberry Pi 4 (backup)']));

        $device = self::getContainer()->get(DeviceRepository::class)->findOneBy(['slug' => 'raspberry-pi-4-backup']);
        self::assertNotNull($device);

        self::assertSame(1, preg_match('/(hz_[0-9A-Za-z]{48})/', $this->tester->getDisplay(), $m));
        $token = self::getContainer()->get(DeviceTokenManager::class)->findValid($m[1]);
        self::assertSame($device, $token?->getDevice());
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidInput(array $input, int $expectedExit): void
    {
        $this->tester->execute(['name' => 'NAS', '--slug' => 'nas']);

        self::assertSame($expectedExit, $this->tester->execute($input));
    }

    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'duplikat' => [['name' => 'NAS 2', '--slug' => 'nas'], Command::FAILURE];
        yield 'zły slug' => [['name' => 'X', '--slug' => 'Zły Slug'], Command::INVALID];
        yield 'pusta nazwa' => [['name' => '   '], Command::INVALID];
    }
}
