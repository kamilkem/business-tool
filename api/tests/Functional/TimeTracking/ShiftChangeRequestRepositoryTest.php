<?php

declare(strict_types=1);

namespace App\Tests\Functional\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Factory\OrganizationFactory;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestKind;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Infrastructure\Doctrine\Orm\Repository\ShiftChangeRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** ShiftChangeRequestRepository against PostgreSQL, with the TenantFilter. */
#[CoversClass(ShiftChangeRequestRepository::class)]
final class ShiftChangeRequestRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string NOW = '2026-11-13 12:00:00';

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private ShiftChangeRequestRepositoryInterface $requests;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->requests = self::getContainer()->get(ShiftChangeRequestRepositoryInterface::class);
    }

    public function test_it_stores_a_request_and_its_decision_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->switchTo($organization);
        $userId = Uuid::v7();
        $shift = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable('2026-11-12 16:00:00'), null);
        $request = ShiftChangeRequest::forShift(Uuid::v7(), $userId, $shift, new \DateTimeImmutable('2026-11-12 15:30:00'), null, 'Clocked in late', new \DateTimeImmutable(self::NOW), ShiftPeriodLocks::none());
        $this->requests->add($request);
        $this->entityManager->clear();

        $found = $this->requests->findById($request->getId()) ?? self::fail('Request not stored.');
        self::assertTrue($found->getUserId()->equals($userId));
        self::assertSame(ShiftChangeRequestKind::NewTimes, $found->getKind());
        self::assertTrue($shift->getId()->equals($found->getShiftId() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable('2026-11-12 15:30:00'), $found->getStartedAt());
        self::assertNull($found->getEndedAt());
        self::assertSame('Clocked in late', $found->getNote());
        self::assertSame(ShiftChangeRequestStatus::Pending, $found->getStatus());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $found->getSubmittedAt());
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));

        $managerId = Uuid::v7();
        $found->reject($managerId, MembershipRole::Manager, 'You clocked in on time', new \DateTimeImmutable('2026-11-13 13:00:00'));
        $this->requests->save($found);
        $this->entityManager->clear();

        $decided = $this->requests->findById($request->getId()) ?? self::fail('Request lost.');
        self::assertSame(ShiftChangeRequestStatus::Rejected, $decided->getStatus());
        self::assertTrue($managerId->equals($decided->getDecidedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable('2026-11-13 13:00:00'), $decided->getDecidedAt());
        self::assertSame('You clocked in on time', $decided->getRejectionReason());
    }

    public function test_it_finds_requests_newest_first_by_status_and_person(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $eve = Uuid::v7();
        $adam = Uuid::v7();
        $oldest = $this->add($eve, '2026-11-11 08:00:00');
        $newest = $this->add($adam, '2026-11-13 08:00:00');
        $middle = $this->add($eve, '2026-11-12 08:00:00');
        $sameSecond = $this->add($eve, '2026-11-12 08:00:00');
        $middle->approve(Uuid::v7(), MembershipRole::Owner, Uuid::v7(), new \DateTimeImmutable(self::NOW));
        $this->requests->save($middle);
        $this->entityManager->clear();

        self::assertSame(self::ids([$newest, $sameSecond, $middle, $oldest]), self::ids($this->requests->findAll()));
        self::assertSame(self::ids([$sameSecond, $middle, $oldest]), self::ids($this->requests->findAll(userId: $eve)));
        self::assertSame(self::ids([$newest, $sameSecond, $oldest]), self::ids($this->requests->findAll(ShiftChangeRequestStatus::Pending)));
        self::assertSame(self::ids([$middle]), self::ids($this->requests->findAll(ShiftChangeRequestStatus::Approved, $eve)));
        self::assertSame([], $this->requests->findAll(ShiftChangeRequestStatus::Rejected));
    }

    public function test_it_does_not_show_another_organizations_requests(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->switchTo($a);
        $elsewhere = $this->add(Uuid::v7(), '2026-11-12 08:00:00');
        $this->entityManager->clear();
        $this->switchTo($b);

        self::assertNull($this->requests->findById($elsewhere->getId()));
        self::assertSame([], $this->requests->findAll());
    }

    private function add(Uuid $userId, string $submittedAt): ShiftChangeRequest
    {
        $request = ShiftChangeRequest::forMissingShift(Uuid::v7(), $userId, new \DateTimeImmutable('2026-11-10 08:00:00'), new \DateTimeImmutable('2026-11-10 16:00:00'), 'My phone was dead', new \DateTimeImmutable($submittedAt), ShiftPeriodLocks::none());
        $this->requests->add($request);

        return $request;
    }

    /**
     * @param list<ShiftChangeRequest> $requests
     *
     * @return list<string>
     */
    private static function ids(array $requests): array
    {
        return array_map(static fn (ShiftChangeRequest $request): string => $request->getId()->toRfc4122(), $requests);
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo(
            $this->entityManager->getReference(Organization::class, $organization->getId())
                ?? throw new \LogicException(),
        );
    }
}
