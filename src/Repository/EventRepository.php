<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\Event;
use App\Enum\EventType;
use App\Enum\Severity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @return array{items: list<Event>, total: int}
     */
    public function search(?Device $device, ?EventType $type, ?Severity $severity, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.device', 'd')->addSelect('d')
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $device) {
            $qb->andWhere('e.device = :device')->setParameter('device', $device);
        }
        if (null !== $type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        if (null !== $severity) {
            $qb->andWhere('e.severity = :severity')->setParameter('severity', $severity);
        }

        /** @var Paginator<Event> $paginator */
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => \count($paginator)];
    }
}
