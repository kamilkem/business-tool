<?php

declare(strict_types=1);

namespace App\Expenses\Infrastructure\Doctrine\Orm\Repository;

use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ExpenseCategory> */
final class ExpenseCategoryRepository extends ServiceEntityRepository implements ExpenseCategoryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseCategory::class);
    }

    public function findById(Uuid $id): ?ExpenseCategory
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?ExpenseCategory
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findAll(bool $includeArchived = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('CASE WHEN c.archivedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN archived')
            ->orderBy('archived', 'ASC')
            ->addOrderBy('c.name', 'ASC');

        if (!$includeArchived) {
            $qb->where('c.archivedAt IS NULL');
        }

        /** @var list<ExpenseCategory> */
        return $qb->getQuery()->getResult();
    }

    public function add(ExpenseCategory $category): void
    {
        $this->getEntityManager()->persist($category);
        $this->flush($category);
    }

    public function save(ExpenseCategory $category): void
    {
        $this->flush($category);
    }

    private function flush(ExpenseCategory $category): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), ExpenseCategory::UNIQUE_NAME)) {
                throw $e;
            }

            throw ExpenseCategoryNameTakenException::of($category->getName());
        }
    }
}
