<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Query\ListUserOrganizations\ListUserOrganizationsHandler;
use App\Organization\Application\Query\ListUserOrganizations\ListUserOrganizationsQuery;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListUserOrganizationsHandler::class)]
final class ListUserOrganizationsHandlerTest extends TestCase
{
    public function test_it_lists_only_the_users_organizations_by_name(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $user = UserMother::any();
        $organizations->add(OrganizationMother::ownedBy($user, 'Zebra Bar'));
        $organizations->add(OrganizationMother::ownedBy($user, 'Apple Pub'));
        $organizations->add(OrganizationMother::ownedBy(UserMother::any('other@example.com'), 'Not Mine'));

        $views = (new ListUserOrganizationsHandler($organizations))(new ListUserOrganizationsQuery($user->getId()->toRfc4122()));

        self::assertSame(['Apple Pub', 'Zebra Bar'], array_map(static fn ($view): string => $view->name, $views));
    }
}
