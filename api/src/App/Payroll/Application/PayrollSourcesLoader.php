<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Payroll\Application\Query\GetPayRate\PayRateView;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesQuery;
use App\Payroll\Domain\Model\PayrollSources;
use App\Payroll\Domain\Model\ShiftRate;
use App\Payroll\Domain\Model\ShiftRateKind;
use App\Payroll\Domain\Model\WorkedShift;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\TimeTracking\Application\Query\ListWorkedShifts\ListWorkedShiftsQuery;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftsView;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftView;
use Symfony\Component\Uid\Uuid;

/**
 * Asks Time tracking for a month's shifts and pending change requests, and the rate queries for the
 * rate of every member and date (ADR 0027 §5–7), in the organization's time zone and currency, or
 * of one member's only (their estimate, #66). The caller has checked who may ask.
 */
final readonly class PayrollSourcesLoader
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    public function load(BusinessPeriod $month, OrganizationView $organization, ?Uuid $userId = null): PayrollSources
    {
        $first = $month->first->toString();
        $last = $month->last->toString();
        $user = $userId?->toRfc4122();

        /** @var WorkedShiftsView $worked */
        $worked = $this->queryBus->ask(new ListWorkedShiftsQuery($first, $last, $organization->timezone, $user));
        /** @var list<PayRateView> $rates */
        $rates = $this->queryBus->ask(new ListPayRatesQuery($first, $last, $user));

        return new PayrollSources(
            $month,
            array_map(static fn (WorkedShiftView $shift): WorkedShift => new WorkedShift(
                Uuid::fromString($shift->shiftId),
                Uuid::fromString($shift->userId),
                BusinessDate::fromString($shift->date),
                $shift->seconds,
            ), $worked->shifts),
            array_map(static fn (PayRateView $rate): ShiftRate => new ShiftRate(
                Uuid::fromString($rate->userId),
                BusinessDate::fromString($rate->date),
                ShiftRateKind::from($rate->kind),
                Uuid::fromString($rate->rateId),
                $rate->amount,
                $rate->currency,
            ), $rates),
            $worked->pendingChangeRequests,
            $organization->currency,
        );
    }
}
