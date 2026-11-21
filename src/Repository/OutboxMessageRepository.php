<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OutboxMessage;
use App\Enum\OutboxStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboxMessage>
 */
class OutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboxMessage::class);
    }

    /**
     * @return list<OutboxMessage>
     */
    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<OutboxMessage> $result */
        $result = $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.nextAttemptAt <= :now')
            ->setParameter('status', OutboxStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return array{items: list<OutboxMessage>, total: int}
     */
    public function search(?OutboxStatus $status, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('o')
            ->orderBy('o.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        /** @var Paginator<OutboxMessage> $paginator */
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => \count($paginator)];
    }
}
