<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\DeleteHourlyRate;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\LockedPayrollDatesLoader;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateNotFoundException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteHourlyRateHandler implements CommandHandlerInterface
{
    public function __construct(
        private HourlyRateRepositoryInterface $rates,
        private LockedPayrollDatesLoader $locks,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayRateNotFoundException        when the rate is not one of the organization's
     * @throws PayRatePeriodLockedException    when a date the rate is in effect on is locked
     */
    public function __invoke(DeleteHourlyRateCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $rate = Uuid::isValid($command->rateId) ? $this->rates->findById(Uuid::fromString($command->rateId)) : null;
        $rate ??= throw PayRateNotFoundException::hourlyRate($command->rateId);

        $rate->delete(
            HourlyRateHistory::of($rate->getUserId(), $this->rates->findByUser($rate->getUserId())),
            $this->locks->load(),
            $actor->id,
            $actor->role,
        );

        $this->rates->remove($rate);
    }
}
