<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ApproveShiftChangeRequest;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\TimeTracking\Domain\Event\ShiftChangeRequestDecided;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestDecisionForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Saves the approval, then publishes ShiftChangeRequestDecided (the requester is e-mailed). The
 * shift has already changed: when the request was decided by someone else in the moment between
 * the two writes, this throws ShiftChangeRequestAlreadyDecidedException and the change stays
 * applied (accepted for now; there is no transaction across the two aggregates, ADR 0004).
 */
final readonly class ApproveShiftChangeRequestHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
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
     */
    public function __invoke(ApproveShiftChangeRequestCommand $command): void
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

        $request->approve($actorId, $actorRole, Uuid::fromString($command->shiftId), $this->clock->now());
        $this->requests->save($request);

        $this->events->publish(new ShiftChangeRequestDecided(
            $command->organizationId,
            $request->getId()->toRfc4122(),
            $request->getUserId()->toRfc4122(),
            $actorId->toRfc4122(),
            $request->getStatus()->value,
        ));
    }
}
