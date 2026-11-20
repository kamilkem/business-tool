<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\SetHourlyRate;

use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\LockedPayrollDatesLoader;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\InvalidPayRateException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SetHourlyRateHandler implements CommandHandlerInterface
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
     * @throws MemberNotFoundException         when the user is not a member, active or deactivated
     * @throws TimeException                   when the date is not a date
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when the member has a rate from that date
     * @throws PayRatePeriodLockedException    when a date the rate would be in effect on is locked
     */
    public function __invoke(SetHourlyRateCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $userId = $actor->memberId($this->queryBus, $command->userId);
        $effectiveFrom = BusinessDate::fromString($command->effectiveFrom);

        $rate = HourlyRate::set(
            Uuid::fromString($command->rateId),
            HourlyRateHistory::of($userId, $this->rates->findByUser($userId)),
            $effectiveFrom,
            $command->amount,
            $actor->organization($this->queryBus)->currency,
            $this->locks->load(),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->rates->add($rate);
    }
}
