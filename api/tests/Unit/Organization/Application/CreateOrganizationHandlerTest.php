<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Command\CreateOrganization\CreateOrganizationCommand;
use App\Organization\Application\Command\CreateOrganization\CreateOrganizationHandler;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\User\Application\InMemoryUserRepository;
use App\User\Domain\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateOrganizationHandler::class)]
final class CreateOrganizationHandlerTest extends TestCase
{
    private const string NOW = '2026-11-11 12:00:00';

    public function test_it_creates_an_organization_owned_by_the_given_user(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $users = new InMemoryUserRepository();
        $owner = UserMother::any();
        $users->add($owner);
        $id = Uuid::v7();
        $events = new RecordingEventPublisher();

        (new CreateOrganizationHandler($organizations, $users, $events, new MockClock(self::NOW)))(new CreateOrganizationCommand(
            $id->toRfc4122(), $owner->getId()->toRfc4122(), 'Corner Pub', '1234567890', 'PL', 'PLN', 'Europe/Warsaw',
        ));

        $organization = $organizations->findById($id);
        self::assertNotNull($organization);
        self::assertSame('Corner Pub', $organization->getName());
        self::assertSame('1234567890', $organization->getTaxId());
        self::assertSame(MembershipRole::Owner, $organization->membershipOf($owner->getId())?->getRole());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $organization->getCreatedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $organization->membershipOf($owner->getId())->getCreatedAt());
        self::assertEquals([new OrganizationCreated($id->toRfc4122(), $owner->getId()->toRfc4122())], $events->events);
    }

    public function test_it_refuses_an_unknown_owner(): void
    {
        $events = new RecordingEventPublisher();
        $handler = new CreateOrganizationHandler(new InMemoryOrganizationRepository(), new InMemoryUserRepository(), $events, new MockClock(self::NOW));

        try {
            $handler(new CreateOrganizationCommand(
                Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), 'Corner Pub', null, 'PL', 'PLN', 'Europe/Warsaw',
            ));
            self::fail('Unknown owner expected.');
        } catch (UserNotFoundException) {
        }

        self::assertSame([], $events->events);
    }
}
