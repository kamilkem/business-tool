<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reporting\Application;

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
use App\Payroll\Application\Query\ListPayrollRuns\ListPayrollRunsQuery;
use App\Payroll\Application\Query\ListPayrollRuns\PayrollRunSummaryView;
use App\Reporting\Application\Query\GetBusinessSummary\BusinessCurrencySummaryView;
use App\Reporting\Application\Query\GetBusinessSummary\BusinessSummaryView;
use App\Reporting\Application\Query\GetBusinessSummary\GetBusinessSummaryHandler;
use App\Reporting\Application\Query\GetBusinessSummary\GetBusinessSummaryQuery;
use App\Reporting\Domain\Exception\BusinessSummaryForbiddenException;
use App\Reporting\Domain\Exception\BusinessSummaryMonthInTheFutureException;
use App\Reporting\Domain\Exception\BusinessSummaryPeriodTooLongException;
use App\Reporting\Domain\Model\MonthPayrollStatus;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * GetBusinessSummaryHandler over a stub query bus that answers as Organization, Income, Expenses and
 * Payroll would (ADR 0029). Today is 20 November 2026 in Europe/Warsaw, so October is complete and
 * November is the current month.
 */
final class GetBusinessSummaryHandlerTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00 UTC';

    private string $organizationId;
    private string $actorId;
    private string $role = 'manager';
    private bool $member = true;
    /** @var array<string, list<RevenueCurrencySummaryView>> by month's first date */
    private array $revenue = [];
    /** @var array<string, list<PurchaseInvoiceCurrencyTotalView>> by month's first date */
    private array $expenses = [];
    /** @var list<PayrollRunSummaryView> */
    private array $runs = [];
    private StubQueryBus $queryBus;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7()->toRfc4122();
        $this->actorId = Uuid::v7()->toRfc4122();
        $this->queryBus = new StubQueryBus($this->answer(...));
    }

    public function test_it_adds_up_net_income_net_expenses_and_the_approved_payroll_per_month(): void
    {
        $this->revenue['2026-10-01'] = [self::income('PLN', total: 300_000, vat: 40_000, entries: 31)];
        $this->expenses['2026-10-01'] = [self::costs('PLN', net: 100_000, vat: 23_000, gross: 123_000, invoices: 12)];
        $this->revenue['2026-11-01'] = [self::income('PLN', total: 150_000, vat: 20_000, entries: 19)];
        $this->expenses['2026-11-01'] = [self::costs('PLN', net: 50_000, vat: 4_000, gross: 54_000, invoices: 3)];
        $october = self::payrollRun('2026-10', 'approved', 'PLN', 80_000);
        $november = self::payrollRun('2026-11', 'draft', 'PLN', 45_000);
        $this->runs = [$november, $october];

        $summary = $this->summary('2026-10', '2026-11');

        self::assertNotNull($summary);
        self::assertSame(['2026-10', '2026-11', 'PLN'], [$summary->from, $summary->to, $summary->currency]);
        [$first, $second] = $summary->months;
        self::assertSame(['2026-10', '2026-10-01', '2026-10-31', true], [$first->month, $first->firstDate, $first->lastDate, $first->complete]);
        self::assertSame([MonthPayrollStatus::Approved, $october->id], [$first->payrollStatus, $first->payrollRunId]);
        self::assertEquals([new BusinessCurrencySummaryView('PLN', 300_000, 40_000, 260_000, 31, 123_000, 23_000, 100_000, 12, 80_000, null, 80_000)], $first->currencies);

        // The current month is a preview; its draft run is an estimate, not a cost.
        self::assertSame(['2026-11', '2026-11-30', false], [$second->month, $second->lastDate, $second->complete]);
        self::assertSame([MonthPayrollStatus::Draft, $november->id], [$second->payrollStatus, $second->payrollRunId]);
        self::assertEquals([new BusinessCurrencySummaryView('PLN', 150_000, 20_000, 130_000, 19, 54_000, 4_000, 50_000, 3, 0, 45_000, 80_000)], $second->currencies);
    }

    public function test_it_gives_zeros_in_the_organizations_currency_for_a_month_without_figures(): void
    {
        $summary = $this->summary('2026-09', '2026-09');

        self::assertNotNull($summary);
        self::assertCount(1, $summary->months);
        self::assertSame(MonthPayrollStatus::None, $summary->months[0]->payrollStatus);
        self::assertNull($summary->months[0]->payrollRunId);
        self::assertEquals([new BusinessCurrencySummaryView('PLN', 0, 0, 0, 0, 0, 0, 0, 0, 0, null, 0)], $summary->months[0]->currencies);
    }

    public function test_it_keeps_each_currency_apart_with_the_organizations_first_and_others_by_code(): void
    {
        $this->revenue['2026-10-01'] = [self::income('PLN', total: 10_000, vat: 0, entries: 1), self::income('USD', total: 500, vat: 0, entries: 1)];
        $this->expenses['2026-10-01'] = [self::costs('PLN', net: 0, vat: 0, gross: 0, invoices: 0), self::costs('EUR', net: 1_000, vat: 230, gross: 1_230, invoices: 1)];
        $this->runs = [self::payrollRun('2026-10', 'approved', 'CZK', 700)];

        $summary = $this->summary('2026-10', '2026-10');

        self::assertNotNull($summary);
        $currencies = $summary->months[0]->currencies;
        self::assertSame(['PLN', 'CZK', 'EUR', 'USD'], array_map(static fn (BusinessCurrencySummaryView $currency): string => $currency->currency, $currencies));
        self::assertSame([10_000, -700, -1_000, 500], array_map(static fn (BusinessCurrencySummaryView $currency): int => $currency->result, $currencies));
        self::assertSame([0, 700, 0, 0], array_map(static fn (BusinessCurrencySummaryView $currency): int => $currency->payrollCost, $currencies));
    }

    public function test_it_asks_income_and_expenses_once_per_month_and_payroll_once(): void
    {
        $this->summary('2026-01', '2026-03');

        $sources = array_values(array_filter($this->queryBus->queries, static fn (QueryInterface $query): bool => !$query instanceof GetMemberQuery && !$query instanceof GetOrganizationQuery));
        self::assertEquals([
            new ListPayrollRunsQuery($this->organizationId, $this->actorId),
            new GetRevenueSummaryQuery($this->organizationId, $this->actorId, '2026-01-01', '2026-01-31'),
            new ListPurchaseInvoicesQuery($this->organizationId, $this->actorId, '2026-01-01', '2026-01-31'),
            new GetRevenueSummaryQuery($this->organizationId, $this->actorId, '2026-02-01', '2026-02-28'),
            new ListPurchaseInvoicesQuery($this->organizationId, $this->actorId, '2026-02-01', '2026-02-28'),
            new GetRevenueSummaryQuery($this->organizationId, $this->actorId, '2026-03-01', '2026-03-31'),
            new ListPurchaseInvoicesQuery($this->organizationId, $this->actorId, '2026-03-01', '2026-03-31'),
        ], $sources);
    }

    public function test_it_covers_twelve_months_across_a_year_end(): void
    {
        $summary = $this->summary('2025-12', '2026-11');

        self::assertNotNull($summary);
        self::assertSame('2025-12', $summary->months[0]->month);
        self::assertSame('2026-11', $summary->months[11]->month);
        self::assertCount(12, $summary->months);
    }

    public function test_it_refuses_more_than_twelve_months(): void
    {
        $this->expectException(BusinessSummaryPeriodTooLongException::class);

        $this->summary('2025-11', '2026-11');
    }

    public function test_it_refuses_a_last_month_before_the_first(): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);

        $this->summary('2026-10', '2026-09');
    }

    public function test_it_refuses_a_value_that_is_not_a_month(): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);

        $this->summary('2026-10-01', '2026-10');
    }

    public function test_it_refuses_a_month_that_has_not_started_in_the_organizations_time_zone(): void
    {
        try {
            $this->summary('2026-11', '2026-12');
            self::fail('A future month was summarized.');
        } catch (BusinessSummaryMonthInTheFutureException $exception) {
            self::assertSame(['month' => '2026-12'], $exception->errorParameters());
        }

        // 30 November 23:30 UTC is already 1 December in Warsaw.
        $summary = $this->summary('2026-12', '2026-12', '2026-11-30 23:30:00 UTC');
        self::assertNotNull($summary);
        self::assertFalse($summary->months[0]->complete);
    }

    public function test_it_refuses_an_employee_before_asking_the_other_modules(): void
    {
        $this->role = 'employee';

        try {
            $this->summary('2026-10', '2026-10');
            self::fail('An employee read the business summary.');
        } catch (BusinessSummaryForbiddenException) {
            self::assertSame([GetMemberQuery::class], array_map(static fn (QueryInterface $query): string => $query::class, $this->queryBus->queries));
        }
    }

    public function test_it_lets_an_owner_read_it(): void
    {
        $this->role = 'owner';

        self::assertNotNull($this->summary('2026-10', '2026-10'));
    }

    public function test_it_returns_null_for_someone_who_is_not_an_active_member(): void
    {
        $this->member = false;

        self::assertNull($this->summary('2026-10', '2026-10'));
    }

    private function summary(string $from, string $to, string $now = self::NOW): ?BusinessSummaryView
    {
        $handler = new GetBusinessSummaryHandler($this->queryBus, new MockClock($now));

        return $handler(new GetBusinessSummaryQuery($this->organizationId, $this->actorId, $from, $to));
    }

    private function answer(QueryInterface $query): mixed
    {
        return match (true) {
            $query instanceof GetMemberQuery => $this->member
                ? new MemberView($query->userId, 'ann@example.com', 'Ann', 'Manager', $this->role, new \DateTimeImmutable('2026-01-01'), false, null, null)
                : null,
            $query instanceof GetOrganizationQuery => new OrganizationView($query->organizationId, 'Corner Pub', null, 'PL', 'PLN', 'Europe/Warsaw', 'semicolon', new \DateTimeImmutable('2026-01-01')),
            $query instanceof ListPayrollRunsQuery => $this->runs,
            $query instanceof GetRevenueSummaryQuery => new RevenueSummaryView($query->from, $query->to, 30, 0, 0, $this->revenue[$query->from] ?? [self::income('PLN', 0, 0, 0)]),
            $query instanceof ListPurchaseInvoicesQuery => new PurchaseInvoiceListView($query->from, $query->to, [], $this->expenses[$query->from] ?? [self::costs('PLN', 0, 0, 0, 0)]),
            default => throw new \LogicException('Unexpected query '.$query::class),
        };
    }

    private static function income(string $currency, int $total, int $vat, int $entries): RevenueCurrencySummaryView
    {
        return new RevenueCurrencySummaryView($currency, $total, $entries, [], [], $total, $vat, []);
    }

    private static function costs(string $currency, int $net, int $vat, int $gross, int $invoices): PurchaseInvoiceCurrencyTotalView
    {
        return new PurchaseInvoiceCurrencyTotalView($currency, $invoices, $net, $vat, $gross, []);
    }

    private static function payrollRun(string $month, string $status, string $currency, int $total): PayrollRunSummaryView
    {
        return new PayrollRunSummaryView(
            Uuid::v7()->toRfc4122(),
            $month,
            $status,
            $currency,
            'none',
            'nearest',
            2,
            36_000,
            0,
            $total,
            0,
            false,
            new \DateTimeImmutable('2026-11-01'),
            'approved' === $status ? new \DateTimeImmutable('2026-11-02') : null,
        );
    }
}
