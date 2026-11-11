<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port: no container, no database. */
final class InMemoryPasswordTokenRepository implements PasswordTokenRepositoryInterface
{
    /** @var array<string, PasswordToken> */
    private array $byId = [];

    public int $saveCallCount = 0;

    public function findBySecretHash(string $secretHash): ?PasswordToken
    {
        foreach ($this->byId as $token) {
            if ($token->getSecretHash() === $secretHash) {
                return $token;
            }
        }

        return null;
    }

    public function findUnusedByUser(Uuid $userId, ?PasswordTokenPurpose $purpose = null): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (PasswordToken $token): bool => $token->getUser()->getId()->equals($userId)
                && null === $token->getUsedAt()
                && null === $token->getInvalidatedAt()
                && (null === $purpose || $purpose === $token->getPurpose()),
        ));
    }

    public function add(PasswordToken $token): void
    {
        $this->byId[$token->getId()->toRfc4122()] = $token;
    }

    public function save(PasswordToken $token): void
    {
        ++$this->saveCallCount;
        $this->byId[$token->getId()->toRfc4122()] = $token;
    }

    /** @return list<PasswordToken> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
