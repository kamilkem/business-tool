<?php

declare(strict_types=1);

namespace App\User\Domain\Repository;

use App\User\Domain\Model\User;
use Symfony\Component\Uid\Uuid;

interface UserRepositoryInterface
{
    public function findById(Uuid $id): ?User;

    /** Matches the e-mail as User stores it (User::normalizeEmail()). */
    public function findByEmail(string $email): ?User;

    public function add(User $user): void;

    public function save(User $user): void;
}
