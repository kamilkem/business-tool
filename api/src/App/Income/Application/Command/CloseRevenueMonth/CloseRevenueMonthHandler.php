<?php

declare(strict_types=1);

namespace App\Income\Application\Command\CloseRevenueMonth;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Exception\RevenueMonthCloseForbiddenException;
use App\Income\Domain\Exception\RevenueMonthNotEndedException;
use App\Income\Domain\Model\RevenueMonth;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CloseRevenueMonthHandler implements CommandHandlerInterface
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
     * @throws RevenueMonthNotEndedException       when the month has not ended in the organization's time zone
     * @throws RevenueMonthAlreadyClosedException  when the month is closed already
     */
    public function __invoke(CloseRevenueMonthCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        $period = BusinessPeriod::monthFromString($command->month);
        $now = $this->clock->now();

        $month = $this->months->findByMonth($period);
        if (null === $month) {
            $this->months->add(RevenueMonth::closeFirst(Uuid::fromString($command->revenueMonthId), $period, $actor->id, $actor->role, $actor->today($this->clock), $now));

            return;
        }

        $month->close($actor->id, $actor->role, $actor->today($this->clock), $now);
        $this->months->save($month);
    }
}
