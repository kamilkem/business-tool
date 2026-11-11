<?php

declare(strict_types=1);

namespace App\User\Domain\Repository;

use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use Symfony\Component\Uid\Uuid;

interface PasswordTokenRepositoryInterface
{
    /** The token with this secret hash (PasswordTokenSecret::hashOf()), in whatever state it is. */
    public function findBySecretHash(string $secretHash): ?PasswordToken;

    /**
     * The user's tokens that were neither used nor invalidated (expired ones included), of one
     * purpose or of all.
     *
     * @return list<PasswordToken>
     */
    public function findUnusedByUser(Uuid $userId, ?PasswordTokenPurpose $purpose = null): array;

    public function add(PasswordToken $token): void;

    public function save(PasswordToken $token): void;
}
