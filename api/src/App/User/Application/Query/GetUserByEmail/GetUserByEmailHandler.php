<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetUserByEmail;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\User\Domain\Repository\UserRepositoryInterface;

final readonly class GetUserByEmailHandler implements QueryHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
    ) {
    }

    public function __invoke(GetUserByEmailQuery $query): ?UserView
    {
        $user = $this->users->findByEmail($query->email);

        return null === $user ? null : UserView::fromUser($user);
    }
}
