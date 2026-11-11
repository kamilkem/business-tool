<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListShifts;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use App\TimeTracking\Domain\Exception\ShiftPeriodTooLongException;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Who may see whose shifts is the Organization module's rule (Organization::memberVisibleTo() and
 * membersVisibleTo()), asked through the query bus together with the names.
 */
final readonly class ListShiftsHandler implements QueryHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<ShiftListItemView>|null newest first
     *
     * @throws TimeException                            when $from or $to is not a date, or $to is before $from
     * @throws ShiftPeriodTooLongException              when the period is longer than ListShiftsQuery::MAX_DAYS
     * @throws OrganizationManagementForbiddenException when an employee asks for someone else's shifts
     * @throws MemberNotFoundException                  when $userId is not a member of the organization
     */
    public function __invoke(ListShiftsQuery $query): ?array
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > ListShiftsQuery::MAX_DAYS) {
            throw ShiftPeriodTooLongException::of($period, ListShiftsQuery::MAX_DAYS);
        }

        if (!Uuid::isValid($query->actorId) || (null !== $query->userId && !Uuid::isValid($query->userId))) {
            return null;
        }

        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($query->organizationId, $query->actorId));
        if (null === $organization) {
            return null;
        }

        $visible = $this->visibleMembers($query);
        if (null === $visible) {
            return null;
        }
        [$members, $onlyUserId] = $visible;

        $timeZone = new \DateTimeZone($organization->timezone);
        $shifts = $this->shifts->findStartedIn(
            $period->toUtcRange($timeZone),
            null === $onlyUserId ? null : Uuid::fromString($onlyUserId),
        );

        $views = [];
        foreach ($shifts as $shift) {
            $member = $members[$shift->getUserId()->toRfc4122()]
                // Memberships are deactivated, never deleted (ADR 0014), so every shift has its member.
                ?? throw new \LogicException(\sprintf('Shift "%s" belongs to someone who is not a member.', $shift->getId()->toRfc4122()));
            $views[] = ShiftListItemView::of($shift, $member, $timeZone);
        }

        return $views;
    }

    /**
     * The members whose shifts the actor sees, by user id, and the one person to narrow the shifts to:
     * the one asked for, nobody (owners and managers see everyone), or the actor (employees).
     *
     * @return array{array<string, MemberView>, ?string}|null null when the actor is not an active member
     */
    private function visibleMembers(ListShiftsQuery $query): ?array
    {
        if (null !== $query->userId) {
            /** @var MemberView|null $member */
            $member = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $query->userId));
            $member ??= throw MemberNotFoundException::of($query->userId, $query->organizationId);

            return [[$member->userId => $member], $member->userId];
        }

        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $query->actorId));
        if (null === $actor) {
            return null;
        }

        if (!MembershipRole::from($actor->role)->canManageOrganization()) {
            return [[$actor->userId => $actor], $actor->userId];
        }

        /** @var list<MemberView>|null $all */
        $all = $this->queryBus->ask(new ListMembersQuery($query->organizationId, $query->actorId, includeDeactivated: true));
        $members = [];
        foreach ($all ?? [] as $member) {
            $members[$member->userId] = $member;
        }

        return [$members, null];
    }
}
