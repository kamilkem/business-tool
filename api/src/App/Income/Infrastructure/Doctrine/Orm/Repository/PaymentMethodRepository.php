<?php

declare(strict_types=1);

namespace App\Income\Infrastructure\Doctrine\Orm\Repository;

use App\Income\Domain\Exception\CashPaymentMethodTakenException;
use App\Income\Domain\Exception\PaymentMethodInUseException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PaymentMethod> */
final class PaymentMethodRepository extends ServiceEntityRepository implements PaymentMethodRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentMethod::class);
    }

    public function findById(Uuid $id): ?PaymentMethod
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?PaymentMethod
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findActiveCash(): ?PaymentMethod
    {
        return $this->findOneBy(['cash' => true, 'deactivatedAt' => null]);
    }

    public function findAll(bool $includeDeactivated = false): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('CASE WHEN m.deactivatedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN deactivated')
            ->orderBy('deactivated', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        if (!$includeDeactivated) {
            $qb->where('m.deactivatedAt IS NULL');
        }

        /** @var list<PaymentMethod> */
        return $qb->getQuery()->getResult();
    }

    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('m')
            ->select('MAX(m.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    public function add(PaymentMethod $method): void
    {
        $this->getEntityManager()->persist($method);
        $this->flush($method);
    }

    public function save(PaymentMethod $method): void
    {
        $this->flush($method);
    }

    public function saveAll(array $methods): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(PaymentMethod $method): void
    {
        $this->getEntityManager()->remove($method);

        try {
            $this->getEntityManager()->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw PaymentMethodInUseException::of($method->getId()->toRfc4122(), $method->getName());
        }
    }

    private function flush(PaymentMethod $method): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), PaymentMethod::ONE_ACTIVE_CASH)) {
                throw CashPaymentMethodTakenException::of($method->getId()->toRfc4122(), $method->getName());
            }
            if (!str_contains($e->getMessage(), PaymentMethod::UNIQUE_NAME)) {
                throw $e;
            }

            throw PaymentMethodNameTakenException::of($method->getName());
        }
    }
}
