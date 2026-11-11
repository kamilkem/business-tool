<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Command\UpdateOrganization\UpdateOrganizationCommand;
use App\Organization\Application\Command\UpdateOrganization\UpdateOrganizationHandler;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateOrganizationHandler::class)]
final class UpdateOrganizationHandlerTest extends TestCase
{
    public function test_it_changes_the_settings_and_saves(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $organizations->add($organization);

        (new UpdateOrganizationHandler($organizations))($this->command($organization->getId(), $owner, 'Renamed'));

        self::assertSame('Renamed', $organization->getName());
        self::assertSame(1, $organizations->saveCallCount);
    }

    public function test_it_hides_organizations_the_actor_does_not_belong_to(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $organizations->add($organization);

        $this->expectException(OrganizationNotFoundException::class);

        (new UpdateOrganizationHandler($organizations))($this->command($organization->getId(), UserMother::any('x@example.com'), 'Renamed'));
    }

    public function test_it_refuses_an_employee(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        $organizations->add($organization);

        $this->expectException(OrganizationManagementForbiddenException::class);

        (new UpdateOrganizationHandler($organizations))($this->command($organization->getId(), $employee, 'Renamed'));
    }

    private function command(Uuid $organizationId, User $actor, string $name): UpdateOrganizationCommand
    {
        return new UpdateOrganizationCommand(
            $organizationId->toRfc4122(), $actor->getId()->toRfc4122(), $name, null, 'PL', 'PLN', 'Europe/Warsaw',
        );
    }
}
