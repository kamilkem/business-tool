<?php

declare(strict_types=1);

namespace App\User\Domain\Repository;

use App\User\Domain\Model\RefreshToken;
use Symfony\Component\Uid\Uuid;

interface RefreshTokenRepositoryInterface
{
    public function findById(Uuid $id): ?RefreshToken;

    /** The token with this secret hash (RefreshTokenSecret::hashOf()), in whatever state it is. */
    public function findBySecretHash(string $secretHash): ?RefreshToken;

    /**
     * The tokens of one session that could still be presented (RefreshToken::isUsableAt()).
     *
     * @return list<RefreshToken>
     */
    public function findUsableBySession(Uuid $sessionId, \DateTimeImmutable $now): array;

    /**
     * The user's tokens, in every session, that could still be presented (RefreshToken::isUsableAt()).
     *
     * @return list<RefreshToken>
     */
    public function findUsableByUser(Uuid $userId, \DateTimeImmutable $now): array;

    public function add(RefreshToken $token): void;

    public function save(RefreshToken $token): void;
}
