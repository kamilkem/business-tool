<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Command\CreateOrganization\CreateOrganizationCommand;
use App\Organization\Application\Command\CreateOrganization\CreateOrganizationHandler;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\User\Application\InMemoryUserRepository;
use App\User\Domain\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateOrganizationHandler::class)]
final class CreateOrganizationHandlerTest extends TestCase
{
    public function test_it_creates_an_organization_owned_by_the_given_user(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $users = new InMemoryUserRepository();
        $owner = UserMother::any();
        $users->add($owner);
        $id = Uuid::v7();

        (new CreateOrganizationHandler($organizations, $users))(new CreateOrganizationCommand(
            $id->toRfc4122(), $owner->getId()->toRfc4122(), 'Corner Pub', '1234567890', 'PL', 'PLN', 'Europe/Warsaw',
        ));

        $organization = $organizations->findById($id);
        self::assertNotNull($organization);
        self::assertSame('Corner Pub', $organization->getName());
        self::assertSame('1234567890', $organization->getTaxId());
        self::assertSame(MembershipRole::Owner, $organization->membershipOf($owner->getId())?->getRole());
    }

    public function test_it_refuses_an_unknown_owner(): void
    {
        $handler = new CreateOrganizationHandler(new InMemoryOrganizationRepository(), new InMemoryUserRepository());

        $this->expectException(UserNotFoundException::class);

        $handler(new CreateOrganizationCommand(
            Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), 'Corner Pub', null, 'PL', 'PLN', 'Europe/Warsaw',
        ));
    }
}
