<?php

declare(strict_types=1);

namespace App\Reporting\Application\Query\GetBusinessSummary;

use App\Expenses\Application\Query\ListPurchaseInvoices\ListPurchaseInvoicesQuery;
use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceCurrencyTotalView;
use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceListView;
use App\Income\Application\Query\GetRevenueSummary\GetRevenueSummaryQuery;
use App\Income\Application\Query\GetRevenueSummary\RevenueCurrencySummaryView;
use App\Income\Application\Query\GetRevenueSummary\RevenueSummaryView;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Application\Query\ListPayrollRuns\ListPayrollRunsQuery;
use App\Payroll\Application\Query\ListPayrollRuns\PayrollRunSummaryView;
use App\Reporting\Domain\Exception\BusinessSummaryForbiddenException;
use App\Reporting\Domain\Exception\BusinessSummaryMonthInTheFutureException;
use App\Reporting\Domain\Exception\BusinessSummaryPeriodTooLongException;
use App\Reporting\Domain\Model\ReportingAccess;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Combines the Income, Expenses and Payroll modules' own queries, asked on the query bus (ADR 0029
 * §1, §5): the revenue summary and the purchase invoice totals once per month, the payroll runs once.
 * Nothing is read from another module's storage, so the totals mean what each module's totals mean.
 */
final readonly class GetBusinessSummaryHandler implements QueryHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TimeException                            when $from or $to is not a month, or $to is before $from
     * @throws BusinessSummaryPeriodTooLongException    when the period has more than GetBusinessSummaryQuery::MAX_MONTHS months
     * @throws BusinessSummaryForbiddenException        when the actor is not an owner or manager
     * @throws BusinessSummaryMonthInTheFutureException when a month of the period has not started
     */
    public function __invoke(GetBusinessSummaryQuery $query): ?BusinessSummaryView
    {
        $months = self::months(BusinessPeriod::monthFromString($query->from), BusinessPeriod::monthFromString($query->to));
        if (\count($months) > GetBusinessSummaryQuery::MAX_MONTHS) {
            throw BusinessSummaryPeriodTooLongException::of($query->from, $query->to, \count($months), GetBusinessSummaryQuery::MAX_MONTHS);
        }

        /** @var MemberView|null $member */
        $member = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $query->actorId));
        if (null === $member) {
            return null;
        }
        ReportingAccess::assertCanRead(Uuid::fromString($member->userId), MembershipRole::from($member->role));

        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($query->organizationId, $query->actorId));
        if (null === $organization) {
            return null;
        }

        $today = BusinessDate::containing($this->clock->now(), new \DateTimeZone($organization->timezone));
        foreach ($months as $month) {
            if ($month->first->isAfter($today)) {
                throw BusinessSummaryMonthInTheFutureException::month($month);
            }
        }

        /** @var list<PayrollRunSummaryView>|null $runs */
        $runs = $this->queryBus->ask(new ListPayrollRunsQuery($query->organizationId, $query->actorId));
        if (null === $runs) {
            return null;
        }
        $runsByMonth = array_column($runs, null, 'month');

        $views = [];
        foreach ($months as $month) {
            $first = $month->first->toString();
            $last = $month->last->toString();

            /** @var RevenueSummaryView|null $revenue */
            $revenue = $this->queryBus->ask(new GetRevenueSummaryQuery($query->organizationId, $query->actorId, $first, $last));
            /** @var PurchaseInvoiceListView|null $expenses */
            $expenses = $this->queryBus->ask(new ListPurchaseInvoicesQuery($query->organizationId, $query->actorId, $first, $last));
            if (null === $revenue || null === $expenses) {
                return null;
            }

            $key = substr($first, 0, 7);
            $run = $runsByMonth[$key] ?? null;
            $views[] = BusinessMonthSummaryView::of(
                $key,
                $first,
                $last,
                $month->last->isBefore($today),
                $run,
                self::currencies($organization->currency, $revenue, $expenses, $run),
            );
        }

        return new BusinessSummaryView($query->from, $query->to, $organization->currency, $views);
    }

    /**
     * @return list<BusinessPeriod> each calendar month from $from to $to
     *
     * @throws TimeException when $to is before $from
     */
    private static function months(BusinessPeriod $from, BusinessPeriod $to): array
    {
        BusinessPeriod::fromDates($from->first, $to->last); // refuses $to before $from

        $months = [];
        for ($month = $from; !$month->first->isAfter($to->first); $month = BusinessPeriod::monthOf($month->last->next())) {
            $months[] = $month;
        }

        return $months;
    }

    /** @return list<BusinessCurrencySummaryView> the organization's currency first, always; others by code */
    private static function currencies(string $organizationCurrency, RevenueSummaryView $revenue, PurchaseInvoiceListView $expenses, ?PayrollRunSummaryView $run): array
    {
        /** @var array<string, RevenueCurrencySummaryView> $income */
        $income = array_column($revenue->currencies, null, 'currency');
        /** @var array<string, PurchaseInvoiceCurrencyTotalView> $costs */
        $costs = array_column($expenses->totals, null, 'currency');

        $others = array_keys($income + $costs + (null === $run ? [] : [$run->currency => true]));
        $others = array_values(array_diff(array_map(strval(...), $others), [$organizationCurrency]));
        sort($others);

        return array_map(
            static fn (string $currency): BusinessCurrencySummaryView => BusinessCurrencySummaryView::of(
                $currency,
                $income[$currency] ?? null,
                $costs[$currency] ?? null,
                $run?->currency === $currency ? $run : null,
            ),
            [$organizationCurrency, ...$others],
        );
    }
}
