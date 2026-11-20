<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Doctrine\Orm\Repository;

use App\Payroll\Domain\Exception\PayrollRunMonthTakenException;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PayrollRun> */
final class PayrollRunRepository extends ServiceEntityRepository implements PayrollRunRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayrollRun::class);
    }

    public function findById(Uuid $id): ?PayrollRun
    {
        return $this->find($id);
    }

    public function findByMonth(BusinessPeriod $month): ?PayrollRun
    {
        /** @var PayrollRun|null */
        return $this->createQueryBuilder('r')
            ->where('r.month = :month')
            ->setParameter('month', $month->first, BusinessDateType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAll(): array
    {
        /** @var list<PayrollRun> */
        return $this->createQueryBuilder('r')
            ->orderBy('r.month', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function add(PayrollRun $run): void
    {
        $this->getEntityManager()->persist($run);
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), PayrollRun::ONE_PER_MONTH)) {
                throw $e;
            }

            throw PayrollRunMonthTakenException::month($run->month());
        }
    }

    public function save(PayrollRun $run): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(PayrollRun $run): void
    {
        $this->getEntityManager()->remove($run);
        $this->getEntityManager()->flush();
    }
}
