<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Organization\Domain\Model\Organization;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<Organization> */
final class OrganizationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Organization::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => Uuid::v7(),
            'name' => self::faker()->company(),
            'owner' => UserFactory::new(),
            'createdAt' => Clock::get()->now(), // the global clock, so mockTime() applies
        ];
    }
}
