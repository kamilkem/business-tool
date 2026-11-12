<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\User\Domain\Model\RefreshToken;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port: no container, no database. */
final class InMemoryRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    /** @var array<string, RefreshToken> */
    private array $byId = [];

    public function findById(Uuid $id): ?RefreshToken
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findBySecretHash(string $secretHash): ?RefreshToken
    {
        foreach ($this->byId as $token) {
            if ($token->getSecretHash() === $secretHash) {
                return $token;
            }
        }

        return null;
    }

    public function findUsableBySession(Uuid $sessionId, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (RefreshToken $token): bool => $token->getSessionId()->equals($sessionId) && $token->isUsableAt($now),
        ));
    }

    public function findUsableByUser(Uuid $userId, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (RefreshToken $token): bool => $token->getUser()->getId()->equals($userId) && $token->isUsableAt($now),
        ));
    }

    public function add(RefreshToken $token): void
    {
        $this->byId[$token->getId()->toRfc4122()] = $token;
    }

    public function save(RefreshToken $token): void
    {
        $this->byId[$token->getId()->toRfc4122()] = $token;
    }

    /** @return list<RefreshToken> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
