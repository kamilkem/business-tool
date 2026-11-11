<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine\Orm\Repository;

use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Repository\MembershipRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Membership> */
final class MembershipRepository extends ServiceEntityRepository implements MembershipRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    public function findOne(Uuid $userId, Uuid $organizationId): ?Membership
    {
        /** @var Membership|null */
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.organization = :organization')
            ->setParameter('user', $userId, UuidType::NAME)
            ->setParameter('organization', $organizationId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByUser(Uuid $userId): array
    {
        /** @var list<Membership> */
        return $this->createQueryBuilder('m')
            ->addSelect('o')
            ->join('m.organization', 'o')
            ->andWhere('m.user = :user')
            ->andWhere('m.deactivatedAt IS NULL')
            ->setParameter('user', $userId, UuidType::NAME)
            ->orderBy('o.name')
            ->getQuery()
            ->getResult();
    }
}
