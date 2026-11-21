<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Subskrypcje obejmujące urządzenie (konkretnie lub „wszystkie”) z aktywnym odbiorcą.
     *
     * @return list<Subscription>
     */
    public function findActiveForDevice(Device $device): array
    {
        /** @var list<Subscription> $result */
        $result = $this->createQueryBuilder('s')
            ->addSelect('r')
            ->join('s.recipient', 'r')
            ->where('s.device = :device OR s.device IS NULL')
            ->andWhere('r.enabled = true')
            ->setParameter('device', $device)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
