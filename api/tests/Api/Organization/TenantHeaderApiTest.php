<?php

declare(strict_types=1);

namespace App\Tests\Api\Organization;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;

final class TenantHeaderApiTest extends ApiTestCase
{
    public function test_it_lets_a_member_select_the_organization(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);

        static::clientFor($user, $organization)->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
    }

    public function test_it_forbids_selecting_an_organization_of_which_the_user_is_not_a_member(): void
    {
        static::clientFor(UserFactory::createOne(), OrganizationFactory::createOne())->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_rejects_a_malformed_organization_id(): void
    {
        static::clientFor(UserFactory::createOne())->request('GET', '/api/me', [
            'headers' => ['X-Organization-Id' => 'not-a-uuid'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }
}
