<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetMyPay;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\PayrollSourcesLoader;
use App\Payroll\Domain\Exception\PayrollMonthInTheFutureException;
use App\Payroll\Domain\Model\PayEstimate;
use App\Payroll\Domain\Model\PayrollRunStatus;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;

/**
 * An approved run's paycheck is what the person is paid; any other month (no run, or a draft that
 * only owners and managers see) is estimated from the same sources a run is generated from, narrowed
 * to the actor (ADR 0027 §10).
 */
final readonly class GetMyPayHandler implements QueryHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private PayrollSourcesLoader $sources,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TimeException                    when the month is not a YYYY-MM month
     * @throws PayrollMonthInTheFutureException when the month starts after the current one
     * @throws OrganizationNotFoundException    when the actor cannot see the organization
     */
    public function __invoke(GetMyPayQuery $query): ?MyPayView
    {
        $actor = PayrollActor::member($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        $organization = $actor->organization($this->queryBus);
        $thisMonth = BusinessPeriod::monthOf(BusinessDate::containing($this->clock->now(), new \DateTimeZone($organization->timezone)));
        $month = null === $query->month ? $thisMonth : BusinessPeriod::monthFromString($query->month);
        if ($month->first->isAfter($thisMonth->first)) {
            throw PayrollMonthInTheFutureException::month($month);
        }

        $run = $this->runs->findByMonth($month);
        if (null !== $run && PayrollRunStatus::Approved === $run->getStatus()) {
            return MyPayView::approved($run, $run->findPaycheck($actor->id));
        }

        return MyPayView::estimate($month, PayEstimate::of($this->sources->load($month, $organization, $actor->id), $actor->id), $organization->currency);
    }
}
