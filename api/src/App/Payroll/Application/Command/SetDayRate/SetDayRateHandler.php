<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\SetDayRate;

use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\LockedPayrollDatesLoader;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\InvalidPayRateException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SetDayRateHandler implements CommandHandlerInterface
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
     * @throws MemberNotFoundException         when the user is not a member, active or deactivated
     * @throws TimeException                   when the date is not a date
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when the member has a day rate on that date
     * @throws PayRatePeriodLockedException    when the date is locked
     */
    public function __invoke(SetDayRateCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $userId = $actor->memberId($this->queryBus, $command->userId);
        $date = BusinessDate::fromString($command->date);

        $dayRate = DayRate::set(
            Uuid::fromString($command->rateId),
            $userId,
            $date,
            $command->amount,
            $actor->organization($this->queryBus)->currency,
            $this->dayRates->findByUserAndDate($userId, $date),
            $this->locks->load(),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->dayRates->add($dayRate);
    }
}
