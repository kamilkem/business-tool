<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ApplyShiftChangeRequest;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\TimeTracking\Domain\Event\ShiftChangeRequestApplied;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestDecisionForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequestKind;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Checks that the actor may decide the pending request, then enters the missing shift
 * (Shift::record()) or gives the shift its new times (Shift::correct(), keeping the shift's note)
 * with the actor as the corrector, saves the shift and publishes ShiftChangeRequestApplied. The
 * shift rules run against the shifts as they are now; when they refuse, nothing is saved and the
 * request stays pending.
 */
final readonly class ApplyShiftChangeRequestHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException                when the actor is not an active member of the organization
     * @throws ShiftChangeRequestNotFoundException          when the request is not one the actor may see
     * @throws ShiftChangeRequestDecisionForbiddenException when the actor is an employee
     * @throws ShiftChangeRequestAlreadyDecidedException    when the request is not pending
     * @throws ShiftNotFoundException                       when the shift to give new times to no longer exists
     * @throws ShiftCannotBeReopenedException               when an ended shift would lose its end
     * @throws ShiftEndNotAfterStartException               when the end is not after the start
     * @throws ShiftInTheFutureException                    when the start or the end is after now
     * @throws ShiftOverlapException                        when the shift would overlap another shift of the person
     */
    public function __invoke(ApplyShiftChangeRequestCommand $command): void
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->actorId));
        $actor ??= throw OrganizationNotFoundException::forId($command->organizationId);
        $actorId = Uuid::fromString($actor->userId);
        $actorRole = MembershipRole::from($actor->role);

        $request = Uuid::isValid($command->requestId) ? $this->requests->findById(Uuid::fromString($command->requestId)) : null;
        if (null === $request || !$request->isVisibleTo($actorId, $actorRole)) {
            throw ShiftChangeRequestNotFoundException::withId($command->requestId);
        }
        $request->assertCanBeDecidedBy($actorId, $actorRole);

        $startedAt = $request->getStartedAt();
        $endedAt = $request->getEndedAt();
        $overlapping = $this->shifts->findOverlapping($request->getUserId(), $startedAt, $endedAt);
        $now = $this->clock->now();

        if (ShiftChangeRequestKind::MissingShift === $request->getKind()) {
            $shift = Shift::record(
                Uuid::fromString($command->newShiftId),
                $request->getUserId(),
                $startedAt,
                $endedAt ?? throw new \LogicException('A missing-shift request always has its end.'),
                $request->getNote(),
                $actorId,
                $actorRole,
                $overlapping,
                $now,
            );
            $this->shifts->add($shift);
        } else {
            $shiftId = $request->getShiftId() ?? throw new \LogicException('A new-times request always has its shift.');
            $shift = $this->shifts->findById($shiftId) ?? throw ShiftNotFoundException::withId($shiftId->toRfc4122());
            $shift->correct($startedAt, $endedAt, $shift->getNote(), $actorId, $actorRole, $overlapping, $now);
            $this->shifts->save($shift);
        }

        $this->events->publish(new ShiftChangeRequestApplied(
            $command->organizationId,
            $request->getId()->toRfc4122(),
            $shift->getId()->toRfc4122(),
            $actorId->toRfc4122(),
        ));
    }
}
