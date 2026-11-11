<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ClockOut;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Domain\Exception\ShiftAlreadyClosedException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** The person clocks out of their own shift only; the time is the clock's now (ADR 0015). */
final readonly class ClockOutHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException  when the person is not an active member of the organization
     * @throws ShiftNotFoundException         when the shift does not exist or is someone else's
     * @throws ShiftAlreadyClosedException    when the shift has already ended (a concurrent clock-out)
     * @throws ShiftEndNotAfterStartException when now is not after the start
     */
    public function __invoke(ClockOutCommand $command): void
    {
        $this->queryBus->ask(new GetOrganizationQuery($command->organizationId, $command->userId))
            ?? throw OrganizationNotFoundException::forId($command->organizationId);

        $shift = Uuid::isValid($command->shiftId) ? $this->shifts->findById(Uuid::fromString($command->shiftId)) : null;
        if (null === $shift || !$shift->getUserId()->equals(Uuid::fromString($command->userId))) {
            throw ShiftNotFoundException::withId($command->shiftId);
        }

        $shift->clockOut($this->clock->now());
        $this->shifts->save($shift);
    }
}
