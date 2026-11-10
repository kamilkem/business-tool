<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetOrganizationHandler::class)]
final class GetOrganizationHandlerTest extends TestCase
{
    public function test_it_returns_the_organization_to_a_member(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner, 'Corner Pub');
        $organizations->add($organization);

        $view = (new GetOrganizationHandler($organizations))(
            new GetOrganizationQuery($organization->getId()->toRfc4122(), $owner->getId()->toRfc4122()),
        );

        self::assertSame('Corner Pub', $view?->name);
    }

    public function test_it_returns_nothing_to_a_non_member(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $organizations->add($organization);

        $view = (new GetOrganizationHandler($organizations))(
            new GetOrganizationQuery($organization->getId()->toRfc4122(), Uuid::v7()->toRfc4122()),
        );

        self::assertNull($view);
    }

    public function test_it_returns_nothing_for_a_malformed_id(): void
    {
        $view = (new GetOrganizationHandler(new InMemoryOrganizationRepository()))(
            new GetOrganizationQuery('not-a-uuid', Uuid::v7()->toRfc4122()),
        );

        self::assertNull($view);
    }
}
