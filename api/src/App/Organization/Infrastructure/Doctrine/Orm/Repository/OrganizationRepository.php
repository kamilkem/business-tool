<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine\Orm\Repository;

use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Organization> */
final class OrganizationRepository extends ServiceEntityRepository implements OrganizationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function findById(Uuid $id): ?Organization
    {
        return $this->find($id);
    }

    public function findByMember(Uuid $userId): array
    {
        /** @var list<Organization> */
        return $this->createQueryBuilder('o')
            ->andWhere(\sprintf('EXISTS (SELECT 1 FROM %s m WHERE m.organization = o AND m.user = :user)', Membership::class))
            ->setParameter('user', $userId, UuidType::NAME)
            ->orderBy('o.name')
            ->getQuery()
            ->getResult();
    }

    public function add(Organization $organization): void
    {
        $this->getEntityManager()->persist($organization);
        $this->getEntityManager()->flush();
    }

    public function save(Organization $organization): void
    {
        $this->getEntityManager()->flush();
    }
}
