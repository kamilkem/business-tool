<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetRefreshToken;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetRefreshTokenHandler implements QueryHandlerInterface
{
    public function __construct(
        private RefreshTokenRepositoryInterface $tokens,
    ) {
    }

    public function __invoke(GetRefreshTokenQuery $query): ?RefreshTokenView
    {
        $token = $this->tokens->findById(Uuid::fromString($query->refreshTokenId));

        return null === $token ? null : RefreshTokenView::fromToken($token);
    }
}
