<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ClockIn;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Any active member clocks in, whatever their role; the time is the clock's now (ADR 0015). */
final readonly class ClockInHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException when the person is not an active member of the organization
     * @throws ShiftAlreadyOpenException     when the person is already clocked in, also when a concurrent
     *                                       clock-in got there first
     */
    public function __invoke(ClockInCommand $command): void
    {
        $this->queryBus->ask(new GetOrganizationQuery($command->organizationId, $command->userId))
            ?? throw OrganizationNotFoundException::forId($command->organizationId);

        $userId = Uuid::fromString($command->userId);
        $shift = Shift::clockIn(
            Uuid::fromString($command->shiftId),
            $userId,
            $this->clock->now(),
            $this->shifts->findOpenByUser($userId),
        );

        $this->shifts->add($shift);
    }
}
