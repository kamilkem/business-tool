<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Doctrine\Orm\Repository;

use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<OrganizationSecret> */
final class OrganizationSecretRepository extends ServiceEntityRepository implements OrganizationSecretRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationSecret::class);
    }

    public function findById(Uuid $id): ?OrganizationSecret
    {
        return $this->find($id);
    }

    public function findNotUnderKey(string $keyId): array
    {
        /** @var list<OrganizationSecret> */
        return $this->createQueryBuilder('s')
            ->where('s.keyId <> :keyId')
            ->setParameter('keyId', $keyId)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function add(OrganizationSecret $secret): void
    {
        $this->getEntityManager()->persist($secret);
        $this->getEntityManager()->flush();
    }

    public function save(OrganizationSecret $secret): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(OrganizationSecret $secret): void
    {
        $this->getEntityManager()->remove($secret);
        $this->getEntityManager()->flush();
    }
}
