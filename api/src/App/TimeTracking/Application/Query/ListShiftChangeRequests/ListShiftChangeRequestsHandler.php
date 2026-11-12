<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListShiftChangeRequests;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListShiftChangeRequestsHandler implements QueryHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @return list<ShiftChangeRequestView>|null newest first */
    public function __invoke(ListShiftChangeRequestsQuery $query): ?array
    {
        $status = null === $query->status ? null : ShiftChangeRequestStatus::from($query->status);

        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $query->actorId));
        if (null === $actor) {
            return null;
        }

        if (MembershipRole::from($actor->role)->canManageOrganization()) {
            $requests = $this->requests->findAll($status);
            /** @var list<MemberView> $members */
            $members = $this->queryBus->ask(new ListMembersQuery($query->organizationId, $query->actorId, includeDeactivated: true)) ?? [];
        } else {
            $requests = $this->requests->findAll($status, Uuid::fromString($actor->userId));
            $members = [$actor];
        }

        $membersById = [];
        foreach ($members as $member) {
            $membersById[$member->userId] = $member;
        }

        $shiftIds = [];
        foreach ($requests as $request) {
            if (null !== $request->getShiftId()) {
                $shiftIds[] = $request->getShiftId();
            }
        }
        $shiftsById = [];
        foreach ($this->shifts->findByIds($shiftIds) as $shift) {
            $shiftsById[$shift->getId()->toRfc4122()] = $shift;
        }

        $views = [];
        foreach ($requests as $request) {
            $member = $membersById[$request->getUserId()->toRfc4122()]
                // Memberships are deactivated, never deleted (ADR 0014), so every request has its member.
                ?? throw new \LogicException(\sprintf('Shift change request "%s" belongs to someone who is not a member.', $request->getId()->toRfc4122()));
            $shift = null === $request->getShiftId() ? null : $shiftsById[$request->getShiftId()->toRfc4122()] ?? null;
            $views[] = ShiftChangeRequestView::of($request, $member, $shift);
        }

        return $views;
    }
}
