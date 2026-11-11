<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\User\Domain\Model\User;
use App\User\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port: no container, no database. */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> */
    private array $byId = [];

    public function findById(Uuid $id): ?User
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->byId as $user) {
            if ($user->getEmail() === User::normalizeEmail($email)) {
                return $user;
            }
        }

        return null;
    }

    public function add(User $user): void
    {
        $this->byId[$user->getId()->toRfc4122()] = $user;
    }

    public function save(User $user): void
    {
        $this->byId[$user->getId()->toRfc4122()] = $user;
    }
}
