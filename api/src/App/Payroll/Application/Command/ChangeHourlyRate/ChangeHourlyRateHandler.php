<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ChangeHourlyRate;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\LockedPayrollDatesLoader;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\InvalidPayRateException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRateNotFoundException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeHourlyRateHandler implements CommandHandlerInterface
{
    public function __construct(
        private HourlyRateRepositoryInterface $rates,
        private LockedPayrollDatesLoader $locks,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayRateNotFoundException        when the rate is not one of the organization's
     * @throws TimeException                   when the date is not a date
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when another rate of the member is from the new date
     * @throws PayRatePeriodLockedException    when a date the rate is or would be in effect on is locked
     */
    public function __invoke(ChangeHourlyRateCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $rate = Uuid::isValid($command->rateId) ? $this->rates->findById(Uuid::fromString($command->rateId)) : null;
        $rate ??= throw PayRateNotFoundException::hourlyRate($command->rateId);

        $rate->change(
            HourlyRateHistory::of($rate->getUserId(), $this->rates->findByUser($rate->getUserId())),
            BusinessDate::fromString($command->effectiveFrom),
            $command->amount,
            $actor->organization($this->queryBus)->currency,
            $this->locks->load(),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->rates->save($rate);
    }
}
