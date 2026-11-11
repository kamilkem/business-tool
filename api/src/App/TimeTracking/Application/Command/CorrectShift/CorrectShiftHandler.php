<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\CorrectShift;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Who may correct shifts and what a correction may do are Shift's rules (Shift::correct()). */
final readonly class CorrectShiftHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException  when the actor is not an active member of the organization
     * @throws ShiftChangeForbiddenException  when the actor is not an owner or manager
     * @throws ShiftNotFoundException         when the shift is not one of the organization's
     * @throws ShiftCannotBeReopenedException when an ended shift would lose its end
     * @throws ShiftEndNotAfterStartException when the end is not after the start
     * @throws ShiftInTheFutureException      when the start or the end is after now
     * @throws ShiftOverlapException          when it would overlap another shift of the person
     */
    public function __invoke(CorrectShiftCommand $command): void
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->actorId));
        $actor ??= throw OrganizationNotFoundException::forId($command->organizationId);
        $actorId = Uuid::fromString($actor->userId);
        $actorRole = MembershipRole::from($actor->role);

        $shift = Uuid::isValid($command->shiftId) ? $this->shifts->findById(Uuid::fromString($command->shiftId)) : null;
        $shift ??= throw ShiftNotFoundException::withId($command->shiftId);

        $startedAt = new \DateTimeImmutable($command->startedAt);
        $endedAt = null === $command->endedAt ? null : new \DateTimeImmutable($command->endedAt);

        $shift->correct(
            $startedAt,
            $endedAt,
            $command->note,
            $actorId,
            $actorRole,
            $this->shifts->findOverlapping($shift->getUserId(), $startedAt, $endedAt),
            $this->clock->now(),
        );

        $this->shifts->save($shift);
    }
}
