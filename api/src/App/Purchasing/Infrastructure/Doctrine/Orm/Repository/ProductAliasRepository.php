<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ProductAlias> */
final class ProductAliasRepository extends ServiceEntityRepository implements ProductAliasRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductAlias::class);
    }

    public function findByKeys(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        // One condition per kind, so the unique index (organization, kind, key) serves each.
        $byKind = [];
        foreach ($keys as $key) {
            $byKind[$key['kind']][$key['key']] = true;
        }
        $query = $this->createQueryBuilder('a')->leftJoin('a.product', 'p')->addSelect('p');
        $conditions = $query->expr()->orX();
        $i = 0;
        foreach ($byKind as $kind => $kindKeys) {
            $conditions->add("a.kind = :kind{$i} AND a.key IN (:keys{$i})");
            $query->setParameter("kind{$i}", AliasKind::from((string) $kind)->value);
            $query->setParameter("keys{$i}", array_map('strval', array_keys($kindKeys)), ArrayParameterType::STRING);
            ++$i;
        }

        /** @var list<ProductAlias> */
        return $query->where($conditions)->getQuery()->getResult();
    }

    public function saveAll(array $aliases): void
    {
        foreach ($aliases as $alias) {
            $this->getEntityManager()->persist($alias);
        }
        $this->getEntityManager()->flush();
    }

    public function moveAliases(Uuid $fromProductId, Uuid $toProductId): void
    {
        // A key is one alias of the organization, so the two products never share one: nothing can collide.
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE product_alias SET product_id = :to WHERE product_id = :from',
            ['from' => $fromProductId->toRfc4122(), 'to' => $toProductId->toRfc4122()],
        );
    }
}
