<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\WithdrawShiftChangeRequest;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestWithdrawalForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestWithdrawnException;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Saves the withdrawal. Nobody is e-mailed: the request just leaves the owners' and managers' pending list. */
final readonly class WithdrawShiftChangeRequestHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException                  when the actor is not an active member of the organization
     * @throws ShiftChangeRequestNotFoundException            when the request is not one the actor may see
     * @throws ShiftChangeRequestWithdrawalForbiddenException when the actor is an owner or manager who did not ask
     * @throws ShiftChangeRequestWithdrawnException           when the request is already withdrawn
     * @throws ShiftChangeRequestAlreadyDecidedException      when the request was approved or rejected
     */
    public function __invoke(WithdrawShiftChangeRequestCommand $command): void
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->actorId));
        $actor ??= throw OrganizationNotFoundException::forId($command->organizationId);
        $actorId = Uuid::fromString($actor->userId);

        $request = Uuid::isValid($command->requestId) ? $this->requests->findById(Uuid::fromString($command->requestId)) : null;
        if (null === $request || !$request->isVisibleTo($actorId, MembershipRole::from($actor->role))) {
            throw ShiftChangeRequestNotFoundException::withId($command->requestId);
        }

        $request->withdraw($actorId, $this->clock->now());
        $this->requests->save($request);
    }
}
