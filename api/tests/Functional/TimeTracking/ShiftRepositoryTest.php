<?php

declare(strict_types=1);

namespace App\Tests\Functional\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\UtcRange;
use App\Tests\Factory\OrganizationFactory;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use App\TimeTracking\Domain\Model\ShiftSource;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use App\TimeTracking\Infrastructure\Doctrine\Orm\Repository\ShiftRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * ShiftRepository against PostgreSQL, including the partial unique index on open shifts and the
 * TenantFilter. The test schema is built from mapping (Foundry mode: schema), so the index comes
 * from the Shift mapping, as in the migration.
 */
#[CoversClass(ShiftRepository::class)]
final class ShiftRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string START = '2026-11-11 16:00:00';
    private const string END = '2026-11-12 00:30:00';

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private ShiftRepositoryInterface $shifts;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->shifts = self::getContainer()->get(ShiftRepositoryInterface::class);
    }

    public function test_it_stores_a_shift_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $userId = Uuid::v7();
        $this->switchTo($organization);

        $shift = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null, 'Opening');
        $this->shifts->add($shift);
        $this->entityManager->clear();

        $found = $this->shifts->findById($shift->getId());
        self::assertNotNull($found);
        self::assertTrue($found->getUserId()->equals($userId));
        self::assertEquals(new \DateTimeImmutable(self::START), $found->getStartedAt());
        self::assertNull($found->getEndedAt());
        self::assertSame('Opening', $found->getNote());
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
    }

    public function test_it_finds_the_open_shift_of_a_person_until_they_clock_out(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $userId = Uuid::v7();
        $shift = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($shift);

        self::assertSame($shift, $this->shifts->findOpenByUser($userId));
        self::assertNull($this->shifts->findOpenByUser(Uuid::v7()));

        $shift->clockOut(new \DateTimeImmutable(self::END));
        $this->shifts->save($shift);
        $this->entityManager->clear();

        self::assertNull($this->shifts->findOpenByUser($userId));
        self::assertEquals(new \DateTimeImmutable(self::END), $this->shifts->findById($shift->getId())?->getEndedAt());
    }

    public function test_it_does_not_show_another_organizations_shifts(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $userId = Uuid::v7();
        $this->switchTo($b);
        $foreign = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($foreign);
        $this->entityManager->clear();

        $this->switchTo($a);

        self::assertNull($this->shifts->findById($foreign->getId()));
        self::assertNull($this->shifts->findOpenByUser($userId));
    }

    public function test_it_rejects_a_second_open_shift_of_the_same_person_in_the_organization(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $userId = Uuid::v7();
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null));

        $this->expectException(ShiftAlreadyOpenException::class);

        // A concurrent clock-in that did not see the first shift: only the index can stop it.
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable('2026-11-11 16:00:01'), null));
    }

    public function test_it_allows_a_new_open_shift_after_the_previous_one_ended(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $userId = Uuid::v7();
        $first = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($first);
        $first->clockOut(new \DateTimeImmutable(self::END));
        $this->shifts->save($first);

        $second = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable('2026-11-12 16:00:00'), null);
        $this->shifts->add($second);

        self::assertSame($second, $this->shifts->findOpenByUser($userId));
    }

    public function test_it_allows_one_open_shift_per_organization_for_the_same_person(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $userId = Uuid::v7();

        $this->switchTo($a);
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null));
        $this->entityManager->clear();
        $this->switchTo($b);
        $inB = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($inB);

        self::assertSame($inB, $this->shifts->findOpenByUser($userId));
    }

    public function test_it_finds_the_shifts_that_started_in_a_range_newest_first(): void
    {
        [$here, $elsewhere] = OrganizationFactory::createMany(2);
        $eve = Uuid::v7();
        $adam = Uuid::v7();
        $this->switchTo($elsewhere);
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $eve, new \DateTimeImmutable('2026-11-11 12:00:00'), null));
        $this->entityManager->clear();
        $this->switchTo($here);
        $this->add($eve, '2026-11-10 22:59:59', '2026-11-11 02:00:00'); // just before the range
        $atStart = $this->add($eve, '2026-11-10 23:00:00', '2026-11-11 07:00:00');
        $adams = $this->add($adam, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        $open = $this->add($eve, '2026-11-11 22:59:59', null);
        $this->add($adam, '2026-11-11 23:00:00', '2026-11-12 03:00:00'); // `to` is excluded
        $this->entityManager->clear();

        // 11 November in Warsaw (UTC+1), given in local time: the range converts it to UTC.
        $warsaw = new \DateTimeZone('Europe/Warsaw');
        $range = new UtcRange(new \DateTimeImmutable('2026-11-11 00:00:00', $warsaw), new \DateTimeImmutable('2026-11-12 00:00:00', $warsaw));

        self::assertSame([$open, $adams, $atStart], self::ids($this->shifts->findStartedIn($range)));
        self::assertSame([$open, $atStart], self::ids($this->shifts->findStartedIn($range, $eve)));
        self::assertSame([], $this->shifts->findStartedIn($range, Uuid::v7()));
    }

    public function test_it_orders_shifts_that_started_in_the_same_second_by_id(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $first = $this->add(Uuid::v7(), '2026-11-11 15:00:00', null);
        $second = $this->add(Uuid::v7(), '2026-11-11 15:00:00', null);
        $this->entityManager->clear();

        $range = new UtcRange(new \DateTimeImmutable('2026-11-11 00:00:00'), new \DateTimeImmutable('2026-11-12 00:00:00'));

        self::assertSame([$second, $first], self::ids($this->shifts->findStartedIn($range)));
    }

    public function test_it_finds_the_persons_shifts_that_share_a_moment_with_a_range(): void
    {
        [$here, $elsewhere] = OrganizationFactory::createMany(2);
        $eve = Uuid::v7();
        $this->switchTo($elsewhere);
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $eve, new \DateTimeImmutable('2026-11-11 12:00:00'), null));
        $this->entityManager->clear();
        $this->switchTo($here);
        $this->add($eve, '2026-11-11 08:00:00', '2026-11-11 10:00:00'); // ends at `from`
        $overlapsStart = $this->add($eve, '2026-11-11 09:00:00', '2026-11-11 10:00:01');
        $inside = $this->add($eve, '2026-11-11 12:00:00', '2026-11-11 13:00:00');
        $evening = $this->add($eve, '2026-11-11 18:00:00', '2026-11-11 20:00:00'); // starts at `to`
        $this->add(Uuid::v7(), '2026-11-11 12:00:00', '2026-11-11 13:00:00'); // someone else's
        $open = $this->add($eve, '2026-11-12 08:00:00', null);
        $this->entityManager->clear();

        $from = new \DateTimeImmutable('2026-11-11 10:00:00');
        self::assertSame([$overlapsStart, $inside], self::ids($this->shifts->findOverlapping($eve, $from, new \DateTimeImmutable('2026-11-11 18:00:00'))));
        self::assertSame([$open], self::ids($this->shifts->findOverlapping($eve, new \DateTimeImmutable('2026-11-12 09:00:00'), new \DateTimeImmutable('2026-11-12 10:00:00'))));
        self::assertSame([$overlapsStart, $inside, $evening, $open], self::ids($this->shifts->findOverlapping($eve, $from, null)));
    }

    public function test_it_stores_who_entered_a_shift_and_when(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $managerId = Uuid::v7();
        $shift = Shift::record(
            Uuid::v7(),
            Uuid::v7(),
            new \DateTimeImmutable(self::START),
            new \DateTimeImmutable(self::END),
            null,
            $managerId,
            MembershipRole::Manager,
            [],
            new \DateTimeImmutable('2026-11-12 09:30:00'),
            ShiftPeriodLocks::none(),
        );
        $this->shifts->add($shift);
        $this->entityManager->clear();

        $found = $this->shifts->findById($shift->getId());
        self::assertNotNull($found);
        self::assertSame(ShiftSource::Manager, $found->getSource());
        self::assertTrue($managerId->equals($found->getCorrectedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable('2026-11-12 09:30:00'), $found->getCorrectedAt());
        self::assertEquals(new \DateTimeImmutable(self::END), $found->getEndedAt());
    }

    public function test_it_finds_the_shifts_with_given_ids_in_the_current_organization_only(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->switchTo($a);
        $elsewhere = $this->add(Uuid::v7(), self::START, self::END);
        $this->entityManager->clear();
        $this->switchTo($b);
        $first = $this->add(Uuid::v7(), self::START, self::END);
        $second = $this->add(Uuid::v7(), self::START, null);
        $this->entityManager->clear();

        $found = self::ids($this->shifts->findByIds(array_map(Uuid::fromString(...), [$first, $second, $elsewhere, Uuid::v7()->toRfc4122()])));

        sort($found);
        $expected = [$first, $second];
        sort($expected);
        self::assertSame($expected, $found);
        self::assertSame([], $this->shifts->findByIds([]));
    }

    public function test_it_removes_a_shift_and_keeps_the_others(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $userId = Uuid::v7();
        $removed = $this->add($userId, '2026-11-11 08:00:00', '2026-11-11 16:00:00');
        $kept = $this->add($userId, self::START, self::END);

        $shift = $this->shifts->findById(Uuid::fromString($removed)) ?? self::fail('Shift not stored.');
        $this->shifts->remove($shift);
        $this->entityManager->clear();

        self::assertNull($this->shifts->findById(Uuid::fromString($removed)));
        self::assertNotNull($this->shifts->findById(Uuid::fromString($kept)));
    }

    public function test_it_finds_the_open_shifts_not_yet_reported_that_started_before_a_moment(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->switchTo($organization);
        $old = $this->add(Uuid::v7(), '2026-11-11 08:00:00', null);
        $older = $this->add(Uuid::v7(), '2026-11-11 06:00:00', null);
        $this->add(Uuid::v7(), '2026-11-11 12:00:00', null);
        $this->add(Uuid::v7(), '2026-11-11 05:00:00', '2026-11-11 23:00:00');
        $reported = $this->shifts->findById(Uuid::fromString($this->add(Uuid::v7(), '2026-11-11 07:00:00', null))) ?? throw new \LogicException();
        $reported->reportAsLong(MaxShiftLength::ofHours(1), new \DateTimeImmutable('2026-11-11 10:00:00'));
        $this->shifts->save($reported);

        $other = OrganizationFactory::createOne();
        $this->switchTo($other);
        $this->add(Uuid::v7(), '2026-11-11 06:00:00', null);
        $this->switchTo($organization);
        $this->entityManager->clear();

        self::assertSame([$older, $old], self::ids($this->shifts->findOpenToReport(new \DateTimeImmutable('2026-11-11 12:00:00'))));
    }

    public function test_it_lists_the_organizations_with_open_shifts_to_report_across_tenants(): void
    {
        $withOne = OrganizationFactory::createOne();
        $this->switchTo($withOne);
        $this->add(Uuid::v7(), '2026-11-11 06:00:00', null);
        $withRecentOnly = OrganizationFactory::createOne();
        $this->switchTo($withRecentOnly);
        $this->add(Uuid::v7(), '2026-11-11 12:00:00', null);
        $withEndedOnly = OrganizationFactory::createOne();
        $this->switchTo($withEndedOnly);
        $this->add(Uuid::v7(), '2026-11-11 05:00:00', '2026-11-11 23:00:00');
        $this->tenantContext->reset();

        self::assertSame(
            [$withOne->getId()->toRfc4122()],
            $this->shifts->findOrganizationIdsWithOpenShiftsToReport(new \DateTimeImmutable('2026-11-11 12:00:00')),
        );
    }

    /** @return string the shift's id */
    private function add(Uuid $userId, string $startedAt, ?string $endedAt): string
    {
        $shift = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable($startedAt), null);
        if (null !== $endedAt) {
            $shift->clockOut(new \DateTimeImmutable($endedAt));
        }
        $this->shifts->add($shift);

        return $shift->getId()->toRfc4122();
    }

    /**
     * @param list<Shift> $shifts
     *
     * @return list<string>
     */
    private static function ids(array $shifts): array
    {
        return array_map(static fn (Shift $shift): string => $shift->getId()->toRfc4122(), $shifts);
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo(
            $this->entityManager->getReference(Organization::class, $organization->getId())
                ?? throw new \LogicException(),
        );
    }
}
