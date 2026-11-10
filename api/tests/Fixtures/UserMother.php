<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\User\Domain\Model\User;
use Symfony\Component\Uid\Uuid;

/** Builds users for unit tests (no container, no database). */
final class UserMother
{
    public static function any(string $email = 'jane@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'Jane', 'Doe');
    }
}
