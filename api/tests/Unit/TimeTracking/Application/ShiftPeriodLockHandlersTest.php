<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Model\Organization;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\TimeTracking\Application\Command\LockShiftPeriod\LockShiftPeriodCommand;
use App\TimeTracking\Application\Command\LockShiftPeriod\LockShiftPeriodHandler;
use App\TimeTracking\Application\Command\UnlockShiftPeriod\UnlockShiftPeriodCommand;
use App\TimeTracking\Application\Command\UnlockShiftPeriod\UnlockShiftPeriodHandler;
use App\TimeTracking\Application\Query\GetLockedShiftPeriod\GetLockedShiftPeriodHandler;
use App\TimeTracking\Application\Query\GetLockedShiftPeriod\GetLockedShiftPeriodQuery;
use App\TimeTracking\Application\Query\GetLockedShiftPeriod\LockedShiftPeriodView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LockShiftPeriodHandler::class)]
#[CoversClass(UnlockShiftPeriodHandler::class)]
#[CoversClass(GetLockedShiftPeriodHandler::class)]
#[CoversClass(LockedShiftPeriodView::class)]
final class ShiftPeriodLockHandlersTest extends TestCase
{
    private const string NOW = '2026-11-05 10:00:00';

    private InMemoryLockedShiftPeriodRepository $periods;
    private InMemoryTenantContext $tenantContext;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->periods = new InMemoryLockedShiftPeriodRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'), createdAt: new \DateTimeImmutable('2026-01-05 09:00:00'));
        $this->tenantContext->switchTo($this->organization);
    }

    public function test_it_locks_the_period_for_its_locker_at_the_clocks_now(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $runId = Uuid::v7()->toRfc4122();

        $this->lock($runId, '2026-10-01', '2026-10-31', $id);

        $period = $this->periods->findByLockedBy(Uuid::fromString($runId));
        self::assertNotNull($period);
        self::assertSame($id, $period->getId()->toRfc4122());
        self::assertTrue(BusinessPeriod::month(2026, 10)->equals($period->getPeriod()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $period->getLockedAt());
    }

    public function test_it_locks_once_per_locker(): void
    {
        $runId = Uuid::v7()->toRfc4122();

        $this->lock($runId, '2026-10-01', '2026-10-31');
        $this->lock($runId, '2026-10-01', '2026-10-31');

        self::assertCount(1, $this->periods->all());
    }

    public function test_it_refuses_a_second_period_for_the_same_locker_as_a_programming_error(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->lock($runId, '2026-10-01', '2026-10-31');

        $this->expectException(\LogicException::class);

        $this->lock($runId, '2026-09-01', '2026-09-30');
    }

    public function test_it_refuses_a_reversed_period(): void
    {
        $this->expectException(TimeException::class);

        $this->lock(Uuid::v7()->toRfc4122(), '2026-10-31', '2026-10-01');
    }

    public function test_it_refuses_to_lock_in_an_organization_other_than_the_selected_one(): void
    {
        $this->tenantContext->switchTo(OrganizationMother::ownedBy(UserMother::any('oscar@example.com')));

        $this->expectException(\LogicException::class);

        $this->lock(Uuid::v7()->toRfc4122(), '2026-10-01', '2026-10-31');
    }

    public function test_it_unlocks_what_the_locker_locked_only(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->lock($runId, '2026-10-01', '2026-10-31');
        $this->lock(Uuid::v7()->toRfc4122(), '2026-09-01', '2026-09-30');

        $this->unlock($runId);
        $this->unlock($runId);
        $this->unlock(Uuid::v7()->toRfc4122());

        self::assertSame(['2026-09-01'], array_map(static fn ($period): string => $period->getPeriod()->first->toString(), $this->periods->all()));
    }

    public function test_it_refuses_to_unlock_in_an_organization_other_than_the_selected_one(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->lock($runId, '2026-10-01', '2026-10-31');
        $this->tenantContext->switchTo(OrganizationMother::ownedBy(UserMother::any('oscar@example.com')));

        $this->expectException(\LogicException::class);

        $this->unlock($runId);
    }

    public function test_it_tells_payroll_whether_a_business_date_is_locked(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->lock($runId, '2026-10-01', '2026-10-31');
        $query = new GetLockedShiftPeriodHandler($this->periods);

        $view = $query(new GetLockedShiftPeriodQuery('2026-10-31'));

        self::assertNotNull($view);
        self::assertSame([$runId, '2026-10-01', '2026-10-31'], [$view->lockedBy, $view->firstDate, $view->lastDate]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $view->lockedAt);
        self::assertNull($query(new GetLockedShiftPeriodQuery('2026-11-01')));
        self::assertNull($query(new GetLockedShiftPeriodQuery('2026-09-30')));
    }

    public function test_it_refuses_a_date_that_is_not_one(): void
    {
        $this->expectException(TimeException::class);

        (new GetLockedShiftPeriodHandler($this->periods))(new GetLockedShiftPeriodQuery('2026-10-32'));
    }

    private function lock(string $lockedBy, string $firstDate, string $lastDate, ?string $id = null): void
    {
        (new LockShiftPeriodHandler($this->periods, $this->tenantContext, new MockClock(self::NOW)))(new LockShiftPeriodCommand(
            $this->organization->getId()->toRfc4122(),
            $id ?? Uuid::v7()->toRfc4122(),
            $lockedBy,
            $firstDate,
            $lastDate,
        ));
    }

    private function unlock(string $lockedBy): void
    {
        (new UnlockShiftPeriodHandler($this->periods, $this->tenantContext))(new UnlockShiftPeriodCommand($this->organization->getId()->toRfc4122(), $lockedBy));
    }
}
