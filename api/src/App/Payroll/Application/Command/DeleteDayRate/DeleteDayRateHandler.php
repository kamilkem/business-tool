<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\DeleteDayRate;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\LockedPayrollDatesLoader;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateNotFoundException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteDayRateHandler implements CommandHandlerInterface
{
    public function __construct(
        private DayRateRepositoryInterface $dayRates,
        private LockedPayrollDatesLoader $locks,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayRateNotFoundException        when the day rate is not one of the organization's
     * @throws PayRatePeriodLockedException    when the date is locked
     */
    public function __invoke(DeleteDayRateCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $dayRate = Uuid::isValid($command->rateId) ? $this->dayRates->findById(Uuid::fromString($command->rateId)) : null;
        $dayRate ??= throw PayRateNotFoundException::dayRate($command->rateId);

        $dayRate->delete($this->locks->load(), $actor->id, $actor->role);

        $this->dayRates->remove($dayRate);
    }
}
