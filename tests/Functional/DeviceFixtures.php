<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Device\DeviceManager;
use App\Entity\Device;
use App\Token\DeviceTokenManager;
use App\Token\IssuedToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait DeviceFixtures
{
    /**
     * @return array{Device, IssuedToken}
     */
    protected function createDeviceWithToken(string $name = 'NAS Debian'): array
    {
        $device = self::getContainer()->get(DeviceManager::class)->create($name);
        $issued = self::getContainer()->get(DeviceTokenManager::class)->issue($device);

        return [$device, $issued];
    }
}
