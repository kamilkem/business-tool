<?php

declare(strict_types=1);

namespace App\TimeTracking\Infrastructure\Doctrine\Orm\Repository;

use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ShiftChangeRequest> */
final class ShiftChangeRequestRepository extends ServiceEntityRepository implements ShiftChangeRequestRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftChangeRequest::class);
    }

    public function findById(Uuid $id): ?ShiftChangeRequest
    {
        return $this->find($id);
    }

    public function findAll(?ShiftChangeRequestStatus $status = null, ?Uuid $userId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.submittedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status->value);
        }

        if (null !== $userId) {
            $qb->andWhere('r.userId = :userId')->setParameter('userId', $userId, UuidType::NAME);
        }

        /** @var list<ShiftChangeRequest> */
        return $qb->getQuery()->getResult();
    }

    public function add(ShiftChangeRequest $request): void
    {
        $this->getEntityManager()->persist($request);
        $this->getEntityManager()->flush();
    }

    public function save(ShiftChangeRequest $request): void
    {
        $this->getEntityManager()->flush();
    }
}
