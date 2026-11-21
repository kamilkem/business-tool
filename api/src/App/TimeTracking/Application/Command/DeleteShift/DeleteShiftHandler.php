<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\DeleteShift;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\ShiftPeriodLocksLoader;
use App\TimeTracking\Domain\Exception\OpenShiftCannotBeDeletedException;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftHasPendingChangeRequestException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Who may delete a shift and which shifts can be deleted are Shift's rules
 * (Shift::assertCanBeDeleted()). An employee is refused before the shift is looked up, as when
 * correcting one (GetManagedShiftQuery), so they learn nothing about which shifts exist.
 */
final readonly class DeleteShiftHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private ShiftChangeRequestRepositoryInterface $requests,
        private QueryBusInterface $queryBus,
        private ShiftPeriodLocksLoader $locks,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException         when the actor is not an active member of the organization
     * @throws ShiftChangeForbiddenException         when the actor is not an owner or manager
     * @throws ShiftNotFoundException                when the shift is not one of the organization's
     * @throws OpenShiftCannotBeDeletedException     when the shift has not ended
     * @throws ShiftPeriodLockedException            when it starts on a locked business date
     * @throws ShiftHasPendingChangeRequestException when a change request about it is pending
     */
    public function __invoke(DeleteShiftCommand $command): void
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->actorId));
        $actor ??= throw OrganizationNotFoundException::forId($command->organizationId);
        $actorId = Uuid::fromString($actor->userId);
        $actorRole = MembershipRole::from($actor->role);
        Shift::assertCanManageShifts($actorId, $actorRole);

        $shift = Uuid::isValid($command->shiftId) ? $this->shifts->findById(Uuid::fromString($command->shiftId)) : null;
        $shift ??= throw ShiftNotFoundException::withId($command->shiftId);

        $shift->assertCanBeDeleted(
            $actorId,
            $actorRole,
            $this->locks->load($command->organizationId, $command->actorId),
            $this->requests->findPendingAboutShift($shift->getId()),
        );

        $this->shifts->remove($shift);
    }
}
