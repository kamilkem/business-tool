<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Purchasing\Domain\Model\SellerUnit;
use App\Purchasing\Domain\Repository\SellerUnitRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SellerUnit> */
final class SellerUnitRepository extends ServiceEntityRepository implements SellerUnitRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SellerUnit::class);
    }

    public function findByPairs(array $pairs): array
    {
        if ([] === $pairs) {
            return [];
        }

        $unitsBySeller = [];
        foreach ($pairs as $pair) {
            $unitsBySeller[$pair['seller']][$pair['unit']] = true;
        }
        $query = $this->createQueryBuilder('u');
        $conditions = $query->expr()->orX();
        $i = 0;
        foreach ($unitsBySeller as $seller => $units) {
            $conditions->add("u.sellerKey = :seller{$i} AND u.unitKey IN (:units{$i})");
            $query->setParameter("seller{$i}", (string) $seller);
            $query->setParameter("units{$i}", array_map('strval', array_keys($units)), ArrayParameterType::STRING);
            ++$i;
        }

        /** @var list<SellerUnit> */
        return $query->where($conditions)->getQuery()->getResult();
    }

    public function saveAll(array $units): void
    {
        foreach ($units as $unit) {
            $this->getEntityManager()->persist($unit);
        }
        $this->getEntityManager()->flush();
    }
}
