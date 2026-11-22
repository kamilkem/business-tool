<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm\Repository;

use App\CashFlow\Domain\Exception\StorageInUseException;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CashStorage> */
final class CashStorageRepository extends ServiceEntityRepository implements CashStorageRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashStorage::class);
    }

    public function findById(Uuid $id): ?CashStorage
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?CashStorage
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findAll(bool $includeDeactivated = false): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('CASE WHEN s.deactivatedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN deactivated')
            ->orderBy('deactivated', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if (!$includeDeactivated) {
            $qb->where('s.deactivatedAt IS NULL');
        }

        /** @var list<CashStorage> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Nothing refers to storages yet. The ledger (#181) and the end-of-day close (#185) add their
     * lines here, with a foreign key that has no ON DELETE (remove()).
     */
    public function isUsed(Uuid $id): bool
    {
        return false;
    }

    public function usedIds(): array
    {
        return [];
    }

    public function add(CashStorage $storage): void
    {
        $this->getEntityManager()->persist($storage);
        $this->flush($storage);
    }

    public function save(CashStorage $storage): void
    {
        $this->flush($storage);
    }

    public function remove(CashStorage $storage): void
    {
        $this->getEntityManager()->remove($storage);

        try {
            $this->getEntityManager()->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw StorageInUseException::of($storage->getId()->toRfc4122(), $storage->getName());
        }
    }

    private function flush(CashStorage $storage): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), CashStorage::UNIQUE_NAME)) {
                throw $e;
            }

            throw StorageNameTakenException::of($storage->getName());
        }
    }
}
