<?php

declare(strict_types=1);

namespace App\Income\Infrastructure\Doctrine\Orm\Repository;

use App\Income\Domain\Exception\RegisterInUseException;
use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Register> */
final class RegisterRepository extends ServiceEntityRepository implements RegisterRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Register::class);
    }

    public function findById(Uuid $id): ?Register
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?Register
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findAll(bool $includeDeactivated = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('CASE WHEN r.deactivatedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN deactivated')
            ->orderBy('deactivated', 'ASC')
            ->addOrderBy('r.name', 'ASC');

        if (!$includeDeactivated) {
            $qb->where('r.deactivatedAt IS NULL');
        }

        /** @var list<Register> */
        return $qb->getQuery()->getResult();
    }

    public function add(Register $register): void
    {
        $this->getEntityManager()->persist($register);
        $this->flush($register);
    }

    public function save(Register $register): void
    {
        $this->flush($register);
    }

    public function remove(Register $register): void
    {
        $this->getEntityManager()->remove($register);

        try {
            $this->getEntityManager()->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw RegisterInUseException::of($register->getId()->toRfc4122(), $register->getName());
        }
    }

    private function flush(Register $register): void
    {
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), Register::UNIQUE_NAME)) {
                throw $e;
            }

            throw RegisterNameTakenException::of($register->getName());
        }
    }
}
