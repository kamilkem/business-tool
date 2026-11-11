<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\User\Domain\Model\User;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<User> */
final class UserFactory extends PersistentObjectFactory
{
    public const string PASSWORD = 'password';

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => Uuid::v7(),
            'email' => self::faker()->unique()->safeEmail(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
        ];
    }

    /** A user who was invited but has not set a password yet (ADR 0013). */
    public function invited(): static
    {
        return $this->afterInstantiate(static function (User $user): void {
            $user->changePassword('');
        });
    }

    protected function initialize(): static
    {
        return $this->afterInstantiate(static function (User $user): void {
            $user->changePassword(self::passwordHash());
        });
    }

    private static function passwordHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(self::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]);
    }
}
