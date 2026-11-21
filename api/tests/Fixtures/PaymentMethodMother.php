<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Income\Domain\Model\PaymentMethod;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Uid\Uuid;

/** Payment methods for unit tests, as an owner adds them (ADR 0030). */
final class PaymentMethodMother
{
    public static function named(string $name, int $position = 0, ?\DateTimeImmutable $createdAt = null): PaymentMethod
    {
        return PaymentMethod::create(Uuid::v7(), $name, null, $position, Uuid::v7(), MembershipRole::Owner, $createdAt ?? new \DateTimeImmutable('2026-01-05 09:00:00'));
    }

    public static function deactivated(string $name, int $position = 0): PaymentMethod
    {
        $method = self::named($name, $position);
        $method->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-02-01 09:00:00'));

        return $method;
    }
}
