<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetManagedShift;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetManagedShiftHandler implements QueryHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws ShiftChangeForbiddenException when the actor is an employee */
    public function __invoke(GetManagedShiftQuery $query): ?ShiftView
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $query->actorId));
        if (null === $actor) {
            return null;
        }

        Shift::assertCanManageShifts(Uuid::fromString($actor->userId), MembershipRole::from($actor->role));

        $shift = Uuid::isValid($query->shiftId) ? $this->shifts->findById(Uuid::fromString($query->shiftId)) : null;

        return null === $shift ? null : ShiftView::fromShift($shift);
    }
}
