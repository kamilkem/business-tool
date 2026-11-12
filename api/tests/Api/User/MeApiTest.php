<?php

declare(strict_types=1);

namespace App\Tests\Api\User;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;

final class MeApiTest extends ApiTestCase
{
    public function test_it_returns_the_user_and_their_memberships(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Manager);

        static::clientFor($user)->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'memberships' => [[
                'role' => 'manager',
                'organization' => [
                    'id' => $organization->getId()->toRfc4122(),
                    'name' => $organization->getName(),
                    'timezone' => $organization->getTimezone(),
                    'currency' => $organization->getCurrency(),
                ],
            ]],
        ]);
    }

    public function test_it_returns_each_organizations_time_zone(): void
    {
        $organization = OrganizationFactory::createOne(['timezone' => 'America/New_York']);
        $owner = ($organization->getMemberships()->first() ?: throw new \LogicException('Owner expected.'))->getUser();

        static::clientFor($owner)->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'memberships' => [['organization' => ['id' => $organization->getId()->toRfc4122(), 'timezone' => 'America/New_York']]],
        ]);
    }

    public function test_it_returns_each_organizations_currency(): void
    {
        $organization = OrganizationFactory::createOne(['currency' => 'EUR']);
        $owner = ($organization->getMemberships()->first() ?: throw new \LogicException('Owner expected.'))->getUser();

        static::clientFor($owner)->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'memberships' => [['organization' => ['id' => $organization->getId()->toRfc4122(), 'currency' => 'EUR']]],
        ]);
    }
}
