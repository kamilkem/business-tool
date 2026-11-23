<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ReopenRevenueMonth;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RevenueMonthCloseForbiddenException;
use App\Income\Domain\Exception\RevenueMonthNotClosedException;
use App\Income\Domain\Model\IncomeAccess;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use Psr\Clock\ClockInterface;

final readonly class ReopenRevenueMonthHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueMonthRepositoryInterface $months,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException       when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException  when the actor is an employee
     * @throws RevenueMonthCloseForbiddenException when the actor is a manager
     * @throws InvalidBusinessPeriodException      when the month is not YYYY-MM
     * @throws RevenueMonthNotClosedException      when the month is open
     */
    public function __invoke(ReopenRevenueMonthCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        $period = BusinessPeriod::monthFromString($command->month);

        $month = $this->months->findByMonth($period);
        if (null === $month) {
            // Never closed: a manager learns that only owners reopen before learning it is open.
            IncomeAccess::assertCanCloseMonths($actor->id, $actor->role);

            throw RevenueMonthNotClosedException::of($command->month);
        }

        $month->reopen($actor->id, $actor->role, $this->clock->now());
        $this->months->save($month);
    }
}
