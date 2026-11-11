<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\AddShift;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The person may be any member, a deactivated one too: shifts are always in the past, and the last
 * weeks of someone who has left still count (ADR 0014). Who may enter shifts is Shift's rule.
 */
final readonly class AddShiftHandler implements CommandHandlerInterface
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
     * @throws MemberNotFoundException        when the person is not a member of the organization
     * @throws ShiftEndNotAfterStartException when the end is not after the start
     * @throws ShiftInTheFutureException      when the start or the end is after now
     * @throws ShiftOverlapException          when it overlaps another shift of the person
     */
    public function __invoke(AddShiftCommand $command): void
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->actorId));
        $actor ??= throw OrganizationNotFoundException::forId($command->organizationId);
        $actorId = Uuid::fromString($actor->userId);
        $actorRole = MembershipRole::from($actor->role);
        Shift::assertCanManageShifts($actorId, $actorRole);

        /** @var MemberView|null $member */
        $member = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->userId));
        $member ??= throw MemberNotFoundException::of($command->userId, $command->organizationId);

        $userId = Uuid::fromString($member->userId);
        $startedAt = new \DateTimeImmutable($command->startedAt);
        $endedAt = new \DateTimeImmutable($command->endedAt);

        $shift = Shift::record(
            Uuid::fromString($command->shiftId),
            $userId,
            $startedAt,
            $endedAt,
            $command->note,
            $actorId,
            $actorRole,
            $this->shifts->findOverlapping($userId, $startedAt, $endedAt),
            $this->clock->now(),
        );

        $this->shifts->add($shift);
    }
}
