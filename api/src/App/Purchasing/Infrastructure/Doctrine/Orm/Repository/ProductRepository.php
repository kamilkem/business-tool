<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Product> */
final class ProductRepository extends ServiceEntityRepository implements ProductRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findById(Uuid $id): ?Product
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?Product
    {
        return $this->findOneBy(['nameKey' => Product::nameKeyOf($name)]);
    }

    public function existsInCategory(Uuid $categoryId): bool
    {
        return null !== $this->findOneBy(['category' => $categoryId]);
    }

    public function search(?array $categoryIds, ?string $nameContains): array
    {
        $query = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->addSelect('c')
            ->orderBy('p.nameKey', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        if (null !== $categoryIds) {
            if ([] === $categoryIds) {
                return [];
            }
            $query->andWhere('p.category IN (:categories)')->setParameter('categories', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $categoryIds), ArrayParameterType::STRING);
        }
        $nameContains = null === $nameContains ? '' : Product::nameKeyOf($nameContains);
        if ('' !== $nameContains) {
            $query->andWhere("p.nameKey LIKE :needle ESCAPE '!'")
                ->setParameter('needle', '%'.strtr($nameContains, ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
        }

        /** @var list<Product> */
        return $query->getQuery()->getResult();
    }

    public function add(Product $product): void
    {
        $this->getEntityManager()->persist($product);
        $this->flush($product);
    }

    public function save(Product $product): void
    {
        $this->flush($product);
    }

    public function remove(Product $product): void
    {
        $this->getEntityManager()->remove($product);
        try {
            $this->getEntityManager()->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw ProductInUseException::of($product->getId()->toRfc4122());
        }
    }

    private function flush(Product $product): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), Product::UNIQUE_NAME)) {
                throw $e;
            }

            throw ProductNameTakenException::of($product->getName());
        }
    }
}
