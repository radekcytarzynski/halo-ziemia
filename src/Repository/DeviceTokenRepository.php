<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\DeviceToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceToken>
 */
class DeviceTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceToken::class);
    }

    /**
     * @return list<DeviceToken>
     */
    public function findValidForDevice(Device $device, \DateTimeImmutable $now): array
    {
        /** @var list<DeviceToken> $tokens */
        $tokens = $this->createQueryBuilder('t')
            ->where('t.device = :device')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt IS NULL OR t.expiresAt > :now')
            ->setParameter('device', $device)
            ->setParameter('now', $now)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    /**
     * @return list<DeviceToken>
     */
    public function findForDevice(Device $device): array
    {
        return $this->findBy(['device' => $device], ['createdAt' => 'DESC']);
    }
}
