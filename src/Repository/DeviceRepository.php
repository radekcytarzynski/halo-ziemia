<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Enum\DeviceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    /**
     * Najpierw problemy (DOWN, CRIT, WARN), potem reszta; w grupie alfabetycznie.
     *
     * @return list<Device>
     */
    public function findForDashboard(): array
    {
        $devices = $this->findBy([], ['name' => 'ASC']);
        $rank = static fn (Device $d): int => match ($d->getStatus()) {
            DeviceStatus::Down => 0,
            DeviceStatus::Crit => 1,
            DeviceStatus::Warn => 2,
            DeviceStatus::Ok => 3,
            DeviceStatus::Pending => 4,
            DeviceStatus::Paused => 5,
        };
        usort($devices, static fn (Device $a, Device $b): int => [$rank($a), mb_strtolower($a->getName())] <=> [$rank($b), mb_strtolower($b->getName())]);

        return $devices;
    }
}
