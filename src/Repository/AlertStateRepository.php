<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlertState;
use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertState>
 */
class AlertStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertState::class);
    }

    /**
     * @return array<string, AlertState> subject → stan
     */
    public function findForDeviceIndexed(Device $device): array
    {
        $result = [];
        foreach ($this->findBy(['device' => $device]) as $state) {
            $result[$state->getSubject()] = $state;
        }

        return $result;
    }
}
