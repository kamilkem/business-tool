<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetShiftChangeRequest;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Who sees which request is ShiftChangeRequest::isVisibleTo(). */
final readonly class GetShiftChangeRequestHandler implements QueryHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetShiftChangeRequestQuery $query): ?ShiftChangeRequestView
    {
        if (!Uuid::isValid($query->requestId)) {
            return null;
        }

        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $query->actorId));
        if (null === $actor) {
            return null;
        }

        $request = $this->requests->findById(Uuid::fromString($query->requestId));
        if (null === $request || !$request->isVisibleTo(Uuid::fromString($actor->userId), MembershipRole::from($actor->role))) {
            return null;
        }

        /** @var MemberView|null $requester */
        $requester = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $request->getUserId()->toRfc4122()));
        // Memberships are deactivated, never deleted (ADR 0014), so every request has its member.
        $requester ??= throw new \LogicException(\sprintf('Shift change request "%s" belongs to someone who is not a member.', $query->requestId));

        $shiftId = $request->getShiftId();

        return ShiftChangeRequestView::of($request, $requester, null === $shiftId ? null : $this->shifts->findById($shiftId));
    }
}
