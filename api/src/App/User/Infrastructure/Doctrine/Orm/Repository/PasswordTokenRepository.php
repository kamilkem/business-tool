<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Doctrine\Orm\Repository;

use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PasswordToken> */
final class PasswordTokenRepository extends ServiceEntityRepository implements PasswordTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordToken::class);
    }

    public function findBySecretHash(string $secretHash): ?PasswordToken
    {
        return $this->findOneBy(['secretHash' => $secretHash]);
    }

    public function findUnusedByUser(Uuid $userId, ?PasswordTokenPurpose $purpose = null): array
    {
        $criteria = ['user' => $userId, 'usedAt' => null, 'invalidatedAt' => null];
        if (null !== $purpose) {
            $criteria['purpose'] = $purpose;
        }

        return $this->findBy($criteria);
    }

    public function add(PasswordToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function save(PasswordToken $token): void
    {
        $this->getEntityManager()->flush();
    }
}
