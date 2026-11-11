<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetUserByEmail;

use App\User\Domain\Model\User;

final readonly class UserView
{
    public function __construct(
        public string $id,
        public string $email,
        public string $firstName,
        public string $lastName,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->getId()->toRfc4122(), $user->getEmail(), $user->getFirstName(), $user->getLastName());
    }
}
