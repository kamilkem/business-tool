<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\User\Domain\Model\User;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Builds users for unit tests (no container, no database). Without an explicit time they are created
 * at the global clock's now, which ClockSensitiveTrait::mockTime() moves.
 */
final class UserMother
{
    public static function any(string $email = 'jane@example.com', ?\DateTimeImmutable $createdAt = null): User
    {
        return new User(Uuid::v7(), $email, $createdAt ?? Clock::get()->now(), 'Jane', 'Doe');
    }
}
