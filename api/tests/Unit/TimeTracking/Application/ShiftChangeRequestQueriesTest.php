<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\GetShiftChangeRequestHandler;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\GetShiftChangeRequestQuery;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use App\TimeTracking\Application\Query\ListShiftChangeRequests\ListShiftChangeRequestsHandler;
use App\TimeTracking\Application\Query\ListShiftChangeRequests\ListShiftChangeRequestsQuery;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetShiftChangeRequestHandler::class)]
#[CoversClass(ListShiftChangeRequestsHandler::class)]
#[CoversClass(ShiftChangeRequestView::class)]
final class ShiftChangeRequestQueriesTest extends ShiftChangeRequestTestCase
{
    public function test_it_shows_a_request_with_the_requesters_name_and_the_shifts_times_now(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);
        $request = $this->newTimesRequest($this->employee, $shift, '2026-11-12 16:00:00', '2026-11-12 23:30:00', 'Forgot to clock out', '2026-11-13 08:00:00');

        $view = $this->get($this->manager, $request->getId()->toRfc4122()) ?? self::fail('Request not visible.');

        self::assertEquals(new ShiftChangeRequestView(
            id: $request->getId()->toRfc4122(),
            userId: $this->employee->getId()->toRfc4122(),
            firstName: $this->employee->getFirstName(),
            lastName: $this->employee->getLastName(),
            kind: 'new_times',
            shiftId: $shift->getId()->toRfc4122(),
            startedAt: new \DateTimeImmutable('2026-11-12 16:00:00'),
            endedAt: new \DateTimeImmutable('2026-11-12 23:30:00'),
            note: 'Forgot to clock out',
            status: 'pending',
            submittedAt: new \DateTimeImmutable('2026-11-13 08:00:00'),
            decidedBy: null,
            decidedAt: null,
            rejectionReason: null,
            withdrawnAt: null,
            shiftStartedAt: new \DateTimeImmutable('2026-11-12 16:00:00'),
            shiftEndedAt: null,
        ), $view);
    }

    public function test_it_shows_a_request_to_owners_managers_and_the_requester_only(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $id = $request->getId()->toRfc4122();

        self::assertNotNull($this->get($this->owner, $id));
        self::assertNotNull($this->get($this->manager, $id));
        $own = $this->get($this->employee, $id) ?? self::fail('The requester does not see their request.');
        self::assertNull($own->shiftId);
        self::assertNull($own->shiftStartedAt);
        self::assertNull($this->get($this->otherEmployee, $id));
        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));

        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-13 09:00:00'));
        self::assertNull($this->get($this->employee, $id), 'A deactivated member sees nothing.');
        self::assertNotNull($this->get($this->owner, $id), 'A deactivated member\'s request stays visible to managers.');
    }

    public function test_it_lists_everyones_requests_newest_first_for_owners_and_managers(): void
    {
        $oldest = $this->missingShiftRequest($this->employee, '2026-11-10 08:00:00', '2026-11-10 16:00:00', submittedAt: '2026-11-11 08:00:00');
        $newest = $this->missingShiftRequest($this->otherEmployee, '2026-11-12 08:00:00', '2026-11-12 16:00:00', submittedAt: '2026-11-13 08:00:00');
        $middle = $this->missingShiftRequest($this->manager, '2026-11-11 08:00:00', '2026-11-11 16:00:00', submittedAt: '2026-11-12 08:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->otherEmployee, new \DateTimeImmutable('2026-11-13 09:00:00'));

        foreach ([$this->owner, $this->manager] as $actor) {
            self::assertSame(
                [$newest->getId()->toRfc4122(), $middle->getId()->toRfc4122(), $oldest->getId()->toRfc4122()],
                array_map(static fn (ShiftChangeRequestView $view): string => $view->id, $this->list($actor) ?? []),
            );
        }
        self::assertSame($this->otherEmployee->getFirstName(), ($this->list($this->owner) ?? [])[0]->firstName);
    }

    public function test_it_lists_an_employees_own_requests_only(): void
    {
        $own = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $this->missingShiftRequest($this->otherEmployee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        self::assertSame([$own->getId()->toRfc4122()], array_map(static fn (ShiftChangeRequestView $view): string => $view->id, $this->list($this->employee) ?? []));
    }

    public function test_it_lists_requests_in_one_status_with_the_shift_entered_for_an_approved_one(): void
    {
        $pending = $this->missingShiftRequest($this->employee, '2026-11-10 08:00:00', '2026-11-10 16:00:00');
        $approved = $this->missingShiftRequest($this->employee, '2026-11-11 08:00:00', '2026-11-11 16:00:00');
        $entered = $this->clocked($this->employee, '2026-11-11 08:00:00', '2026-11-11 16:00:00');
        $approved->approve($this->owner->getId(), MembershipRole::Owner, $entered->getId(), new \DateTimeImmutable(self::NOW));
        $rejected = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $rejected->reject($this->owner->getId(), MembershipRole::Owner, 'No', new \DateTimeImmutable(self::NOW));

        self::assertSame([$pending->getId()->toRfc4122()], array_map(static fn (ShiftChangeRequestView $view): string => $view->id, $this->list($this->manager, 'pending') ?? []));
        self::assertSame([$rejected->getId()->toRfc4122()], array_map(static fn (ShiftChangeRequestView $view): string => $view->id, $this->list($this->employee, 'rejected') ?? []));
        $approvedViews = $this->list($this->owner, 'approved') ?? [];
        self::assertCount(1, $approvedViews);
        self::assertSame($entered->getId()->toRfc4122(), $approvedViews[0]->shiftId);
        self::assertEquals(new \DateTimeImmutable('2026-11-11 16:00:00'), $approvedViews[0]->shiftEndedAt);
    }

    public function test_it_lists_nothing_for_someone_who_is_not_an_active_member(): void
    {
        $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-13 09:00:00'));

        self::assertNull($this->list($this->employee));
        self::assertNull((new ListShiftChangeRequestsHandler($this->requests, $this->shifts, $this->queryBus))(new ListShiftChangeRequestsQuery($this->organizationId(), Uuid::v7()->toRfc4122())));
    }

    private function get(User $actor, string $requestId): ?ShiftChangeRequestView
    {
        return (new GetShiftChangeRequestHandler($this->requests, $this->shifts, $this->queryBus))(
            new GetShiftChangeRequestQuery($this->organizationId(), $actor->getId()->toRfc4122(), $requestId),
        );
    }

    /** @return list<ShiftChangeRequestView>|null */
    private function list(User $actor, ?string $status = null): ?array
    {
        return (new ListShiftChangeRequestsHandler($this->requests, $this->shifts, $this->queryBus))(
            new ListShiftChangeRequestsQuery($this->organizationId(), $actor->getId()->toRfc4122(), $status),
        );
    }
}
