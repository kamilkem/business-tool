<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetRefreshToken;

use App\User\Domain\Model\RefreshToken;

final readonly class RefreshTokenView
{
    public function __construct(
        public string $id,
        public string $userId,
        /** The user's identifier for signing in (and the JWT). */
        public string $userEmail,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public static function fromToken(RefreshToken $token): self
    {
        return new self(
            $token->getId()->toRfc4122(),
            $token->getUser()->getId()->toRfc4122(),
            $token->getUser()->getEmail(),
            $token->getExpiresAt(),
        );
    }
}
