<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm\Repository;

use App\CashFlow\Domain\Exception\DenominationInUseException;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Denomination> */
final class DenominationRepository extends ServiceEntityRepository implements DenominationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Denomination::class);
    }

    public function findById(Uuid $id): ?Denomination
    {
        return $this->find($id);
    }

    public function findByValue(string $currency, int $value): ?Denomination
    {
        return $this->findOneBy(['currency' => strtoupper($currency), 'value' => $value]);
    }

    public function findByCurrency(string $currency, bool $includeDeactivated = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->addSelect('CASE WHEN d.deactivatedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN deactivated')
            ->where('d.currency = :currency')
            ->setParameter('currency', strtoupper($currency))
            ->orderBy('deactivated', 'ASC')
            ->addOrderBy('d.value', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if (!$includeDeactivated) {
            $qb->andWhere('d.deactivatedAt IS NULL');
        }

        /** @var list<Denomination> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Nothing refers to denominations yet. The ledger (#181) and the end-of-day close (#185) add
     * their lines here, with a foreign key that has no ON DELETE (remove()).
     */
    public function isUsed(Uuid $id): bool
    {
        return false;
    }

    public function usedIds(): array
    {
        return [];
    }

    public function add(Denomination $denomination): void
    {
        $this->getEntityManager()->persist($denomination);

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), Denomination::UNIQUE_VALUE)) {
                throw $e;
            }

            throw DenominationValueTakenException::of($denomination->getCurrency(), $denomination->getValue());
        }
    }

    public function save(Denomination $denomination): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(Denomination $denomination): void
    {
        $this->getEntityManager()->remove($denomination);

        try {
            $this->getEntityManager()->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw DenominationInUseException::of($denomination->getId()->toRfc4122(), $denomination->getCurrency(), $denomination->getValue());
        }
    }
}
