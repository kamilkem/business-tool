<?php

declare(strict_types=1);

namespace App\TimeTracking\Infrastructure\Doctrine\Orm\Repository;

use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Shift> */
final class ShiftRepository extends ServiceEntityRepository implements ShiftRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shift::class);
    }

    public function findById(Uuid $id): ?Shift
    {
        return $this->find($id);
    }

    public function findOpenByUser(Uuid $userId): ?Shift
    {
        return $this->findOneBy(['userId' => $userId, 'endedAt' => null]);
    }

    public function add(Shift $shift): void
    {
        $this->getEntityManager()->persist($shift);

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), Shift::ONE_OPEN_SHIFT_PER_USER)) {
                throw $e;
            }

            throw ShiftAlreadyOpenException::forUser($shift->getUserId()->toRfc4122());
        }
    }

    public function save(Shift $shift): void
    {
        $this->getEntityManager()->flush();
    }
}
