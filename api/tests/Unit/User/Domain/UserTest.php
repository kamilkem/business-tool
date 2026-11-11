<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Domain;

use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function test_it_stores_the_email_trimmed_and_lower_cased(): void
    {
        $user = new User(Uuid::v7(), '  Eve.Nowak@Example.COM ', self::createdAt());

        self::assertSame('eve.nowak@example.com', $user->getEmail());
        self::assertSame('eve.nowak@example.com', User::normalizeEmail(' EVE.nowak@example.com'));
    }

    public function test_it_starts_without_a_password(): void
    {
        $user = new User(Uuid::v7(), 'eve@example.com', self::createdAt(), 'Eve', 'Nowak');

        self::assertFalse($user->hasPassword());
    }

    public function test_it_records_when_it_was_created(): void
    {
        $user = new User(Uuid::v7(), 'eve@example.com', self::createdAt());

        self::assertEquals(self::createdAt(), $user->getCreatedAt());
    }

    private static function createdAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-01 09:00:00');
    }
}
