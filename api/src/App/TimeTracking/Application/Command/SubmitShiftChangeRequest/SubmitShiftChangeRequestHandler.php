<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\SubmitShiftChangeRequest;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\TimeTracking\Application\ShiftPeriodLocksLoader;
use App\TimeTracking\Domain\Event\ShiftChangeRequestSubmitted;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestChangesNothingException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNoteRequiredException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Saves the request, then publishes ShiftChangeRequestSubmitted (owners and managers are e-mailed). */
final readonly class SubmitShiftChangeRequestHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
        private ShiftPeriodLocksLoader $locks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException             when the actor is not an active member of the organization
     * @throws ShiftNotFoundException                    when the shift is not one of the actor's in the organization
     * @throws ShiftCannotBeReopenedException            when an ended shift would lose its end
     * @throws ShiftChangeRequestChangesNothingException when the times are the shift's own
     * @throws ShiftChangeRequestNoteRequiredException   when the note is blank
     * @throws ShiftEndNotAfterStartException            when the end is not after the start
     * @throws ShiftInTheFutureException                 when the start or the end is after now
     * @throws ShiftPeriodLockedException                when the shift's start or the asked start is on a locked business date
     */
    public function __invoke(SubmitShiftChangeRequestCommand $command): void
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->actorId));
        $actor ??= throw OrganizationNotFoundException::forId($command->organizationId);
        $actorId = Uuid::fromString($actor->userId);

        $id = Uuid::fromString($command->requestId);
        $startedAt = new \DateTimeImmutable($command->startedAt);
        $endedAt = null === $command->endedAt ? null : new \DateTimeImmutable($command->endedAt);
        $now = $this->clock->now();
        $locks = $this->locks->load($command->organizationId, $command->actorId);

        if (null === $command->shiftId) {
            $request = ShiftChangeRequest::forMissingShift(
                $id,
                $actorId,
                $startedAt,
                $endedAt ?? throw new \InvalidArgumentException('A missing shift is asked for with its end.'),
                $command->note,
                $now,
                $locks,
            );
        } else {
            $shift = Uuid::isValid($command->shiftId) ? $this->shifts->findById(Uuid::fromString($command->shiftId)) : null;
            $shift ??= throw ShiftNotFoundException::withId($command->shiftId);
            $request = ShiftChangeRequest::forShift($id, $actorId, $shift, $startedAt, $endedAt, $command->note, $now, $locks);
        }

        $this->requests->add($request);

        $this->events->publish(new ShiftChangeRequestSubmitted(
            $command->organizationId,
            $id->toRfc4122(),
            $actorId->toRfc4122(),
        ));
    }
}
