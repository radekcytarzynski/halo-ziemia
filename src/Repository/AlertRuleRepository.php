<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlertRule;
use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertRule>
 */
class AlertRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertRule::class);
    }

    /**
     * @return list<AlertRule>
     */
    public function findEnabledForDevice(Device $device): array
    {
        return $this->findBy(['device' => $device, 'enabled' => true], ['id' => 'ASC']);
    }
}
