<?php

declare(strict_types=1);

namespace App\Tests\Api\User;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;

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
                'organization' => ['id' => $organization->getId()->toRfc4122(), 'name' => $organization->getName()],
            ]],
        ]);
    }
}
