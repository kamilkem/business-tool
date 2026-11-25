<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ProductCategory> */
final class ProductCategoryRepository extends ServiceEntityRepository implements ProductCategoryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductCategory::class);
    }

    public function findById(Uuid $id): ?ProductCategory
    {
        return $this->find($id);
    }

    public function findSibling(?Uuid $parentId, string $name): ?ProductCategory
    {
        return $this->findOneBy(['parent' => $parentId, 'nameKey' => ProductCategory::nameKeyOf($name)]);
    }

    public function hasSubcategories(Uuid $id): bool
    {
        return null !== $this->findOneBy(['parent' => $id]);
    }

    public function findAll(): array
    {
        /** @var list<ProductCategory> */
        return $this->createQueryBuilder('c')
            ->orderBy('c.nameKey', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function add(ProductCategory $category): void
    {
        $this->getEntityManager()->persist($category);
        $this->flush($category);
    }

    public function save(ProductCategory $category): void
    {
        $this->flush($category);
    }

    public function remove(ProductCategory $category): void
    {
        $this->getEntityManager()->remove($category);
        try {
            $this->getEntityManager()->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            // The foreign key that refused names the table that refers to the category.
            throw str_contains($e->getMessage(), 'on table "product"')
                ? ProductCategoryInUseException::hasProducts($category->getId()->toRfc4122())
                : ProductCategoryInUseException::hasSubcategories($category->getId()->toRfc4122());
        }
    }

    private function flush(ProductCategory $category): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), ProductCategory::UNIQUE_NAME_UNDER_PARENT) && !str_contains($e->getMessage(), ProductCategory::UNIQUE_NAME_AT_TOP_LEVEL)) {
                throw $e;
            }

            throw ProductCategoryNameTakenException::of($category->getName(), $category->getParent()?->getId()->toRfc4122());
        }
    }
}
