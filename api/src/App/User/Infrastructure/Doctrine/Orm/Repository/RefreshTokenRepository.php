<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Doctrine\Orm\Repository;

use App\User\Domain\Model\RefreshToken;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<RefreshToken> */
final class RefreshTokenRepository extends ServiceEntityRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findById(Uuid $id): ?RefreshToken
    {
        return $this->find($id);
    }

    public function findBySecretHash(string $secretHash): ?RefreshToken
    {
        return $this->findOneBy(['secretHash' => $secretHash]);
    }

    public function findUsableBySession(Uuid $sessionId, \DateTimeImmutable $now): array
    {
        /** @var list<RefreshToken> */
        return $this->usableAt($now)
            ->andWhere('t.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->getQuery()
            ->getResult();
    }

    public function findUsableByUser(Uuid $userId, \DateTimeImmutable $now): array
    {
        /** @var list<RefreshToken> */
        return $this->usableAt($now)
            ->andWhere('t.user = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getResult();
    }

    public function add(RefreshToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function save(RefreshToken $token): void
    {
        $this->getEntityManager()->flush();
    }

    /** The SQL form of RefreshToken::isUsableAt(). */
    private function usableAt(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->andWhere('t.rotatedAt IS NULL OR t.rotatedAt >= :graceCutoff')
            ->setParameter('now', $now)
            ->setParameter('graceCutoff', RefreshToken::rotationGraceCutoff($now));
    }
}
