<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Organization\Domain\Model\Organization;
use App\User\Domain\Model\User;
use Symfony\Component\Uid\Uuid;

/** Builds organizations for unit tests (no container, no database). */
final class OrganizationMother
{
    public static function ownedBy(User $owner, string $name = 'Corner Pub'): Organization
    {
        return new Organization(Uuid::v7(), $name, $owner);
    }
}
