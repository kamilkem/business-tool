<?php

declare(strict_types=1);

namespace App\Income\Infrastructure\Doctrine\Orm\Repository;

use App\Income\Domain\Exception\RevenueEntryAlreadyExistsException;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\RevenuePaymentLine;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<RevenueEntry> */
final class RevenueEntryRepository extends ServiceEntityRepository implements RevenueEntryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RevenueEntry::class);
    }

    public function findById(Uuid $id): ?RevenueEntry
    {
        return $this->find($id);
    }

    public function findByRegisterAndDate(Uuid $registerId, BusinessDate $businessDate): ?RevenueEntry
    {
        /** @var ?RevenueEntry */
        return $this->createQueryBuilder('e')
            ->where('IDENTITY(e.register) = :registerId')
            ->andWhere('e.businessDate = :businessDate')
            ->setParameter('registerId', $registerId, UuidType::NAME)
            ->setParameter('businessDate', $businessDate, BusinessDateType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByDayCloseId(Uuid $dayCloseId): ?RevenueEntry
    {
        return $this->findOneBy(['dayCloseId' => $dayCloseId]);
    }

    public function findInPeriod(BusinessPeriod $period, ?Uuid $registerId = null): array
    {
        // The lines are fetched with the entries: a list shows them all (#43).
        $qb = $this->createQueryBuilder('e')
            ->join('e.register', 'r')
            ->leftJoin('e.paymentLines', 'p')
            ->leftJoin('p.paymentMethod', 'm')
            ->leftJoin('e.vatLines', 'v')
            ->addSelect('r', 'p', 'm', 'v')
            ->where('e.businessDate >= :first')
            ->andWhere('e.businessDate <= :last')
            ->setParameter('first', $period->first, BusinessDateType::NAME)
            ->setParameter('last', $period->last, BusinessDateType::NAME)
            ->orderBy('e.businessDate', 'ASC')
            ->addOrderBy('r.name', 'ASC');

        if (null !== $registerId) {
            $qb->andWhere('r.id = :registerId')->setParameter('registerId', $registerId, UuidType::NAME);
        }

        /** @var list<RevenueEntry> */
        return $qb->getQuery()->getResult();
    }

    public function usesRegister(Uuid $registerId): bool
    {
        $found = $this->createQueryBuilder('e')
            ->select('1')
            ->where('IDENTITY(e.register) = :registerId')
            ->setParameter('registerId', $registerId, UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $found;
    }

    public function usedRegisterIds(): array
    {
        /** @var list<array{id: Uuid|string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT IDENTITY(e.register) AS id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : Uuid::fromString($row['id'])->toRfc4122(), $rows);
    }

    public function usesPaymentMethod(Uuid $paymentMethodId): bool
    {
        $found = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(RevenuePaymentLine::class, 'l')
            ->where('IDENTITY(l.paymentMethod) = :paymentMethodId')
            ->setParameter('paymentMethodId', $paymentMethodId, UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $found;
    }

    public function usedPaymentMethodIds(): array
    {
        /** @var list<array{id: Uuid|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(l.paymentMethod) AS id')
            ->from(RevenuePaymentLine::class, 'l')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : Uuid::fromString($row['id'])->toRfc4122(), $rows);
    }

    public function add(RevenueEntry $entry): void
    {
        $this->getEntityManager()->persist($entry);

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), RevenueEntry::ONE_PER_REGISTER_AND_DATE)) {
                throw $e;
            }

            throw RevenueEntryAlreadyExistsException::for($entry->getRegister()->getId()->toRfc4122(), $entry->getBusinessDate()->toString());
        }
    }

    public function save(RevenueEntry $entry): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(RevenueEntry $entry): void
    {
        $this->getEntityManager()->remove($entry);
        $this->getEntityManager()->flush();
    }
}
