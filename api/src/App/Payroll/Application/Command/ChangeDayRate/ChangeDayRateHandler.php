<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ChangeDayRate;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\LockedPayrollDatesLoader;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\InvalidPayRateException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRateNotFoundException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeDayRateHandler implements CommandHandlerInterface
{
    public function __construct(
        private DayRateRepositoryInterface $dayRates,
        private LockedPayrollDatesLoader $locks,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayRateNotFoundException        when the day rate is not one of the organization's
     * @throws TimeException                   when the date is not a date
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when another day rate of the member is on the new date
     * @throws PayRatePeriodLockedException    when the old or the new date is locked
     */
    public function __invoke(ChangeDayRateCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $dayRate = Uuid::isValid($command->rateId) ? $this->dayRates->findById(Uuid::fromString($command->rateId)) : null;
        $dayRate ??= throw PayRateNotFoundException::dayRate($command->rateId);
        $date = BusinessDate::fromString($command->date);

        $dayRate->change(
            $date,
            $command->amount,
            $actor->organization($this->queryBus)->currency,
            $this->dayRates->findByUserAndDate($dayRate->getUserId(), $date),
            $this->locks->load(),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->dayRates->save($dayRate);
    }
}
