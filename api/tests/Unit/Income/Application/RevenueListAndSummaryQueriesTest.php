<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Application\Query\GetRevenueEntry\RevenueVatLineView;
use App\Income\Application\Query\GetRevenueSummary\GetRevenueSummaryHandler;
use App\Income\Application\Query\GetRevenueSummary\GetRevenueSummaryQuery;
use App\Income\Application\Query\GetRevenueSummary\RevenueCurrencySummaryView;
use App\Income\Application\Query\GetRevenueSummary\RevenueRegisterTotalView;
use App\Income\Application\Query\GetRevenueSummary\RevenueSummaryView;
use App\Income\Application\Query\ListRevenueEntries\ListRevenueEntriesHandler;
use App\Income\Application\Query\ListRevenueEntries\ListRevenueEntriesQuery;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Income\Domain\Exception\RevenuePeriodTooLongException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethodOrder;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ListRevenueEntriesHandler::class)]
#[CoversClass(GetRevenueSummaryHandler::class)]
#[CoversClass(RevenueSummaryView::class)]
#[CoversClass(RevenueCurrencySummaryView::class)]
final class RevenueListAndSummaryQueriesTest extends IncomeHandlerTestCase
{
    private Register $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kitchen = Register::create(Uuid::v7(), 'Kitchen', null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-01-05'));
        $this->registers->add($this->kitchen);
    }

    public function test_it_lists_the_entries_of_a_period_as_views(): void
    {
        $inPeriod = $this->recorded('2026-10-01');
        $this->recorded('2026-09-30');
        $this->recorded('2026-11-01');

        $views = $this->list($this->manager->getId()->toRfc4122(), '2026-10-01', '2026-10-31');

        self::assertEquals([RevenueEntryView::fromEntry($inPeriod)], $views);
    }

    public function test_it_lists_one_registers_entries(): void
    {
        $this->recorded('2026-11-01');
        $kitchen = $this->record($this->kitchen, '2026-11-01', [new PaymentAmount($this->card, 500)]);

        $views = $this->list($this->owner->getId()->toRfc4122(), '2026-11-01', '2026-11-30', $this->kitchen->getId()->toRfc4122());

        self::assertSame([$kitchen->getId()->toRfc4122()], array_map(static fn (RevenueEntryView $view): string => $view->id, $views ?? []));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownRegisters(): iterable
    {
        yield 'not a register' => [Uuid::v7()->toRfc4122()];
        yield 'not a UUID' => ['bar'];
    }

    #[DataProvider('unknownRegisters')]
    public function test_it_refuses_to_list_a_register_the_organization_does_not_have(string $registerId): void
    {
        $this->expectException(RegisterNotFoundException::class);

        $this->list($this->owner->getId()->toRfc4122(), '2026-11-01', '2026-11-30', $registerId);
    }

    public function test_it_lists_up_to_a_quarter(): void
    {
        $this->recorded('2026-11-01');

        self::assertCount(1, $this->list($this->owner->getId()->toRfc4122(), '2026-09-01', '2026-12-01') ?? []);

        $this->expectException(RevenuePeriodTooLongException::class);
        $this->list($this->owner->getId()->toRfc4122(), '2026-08-31', '2026-12-01');
    }

    public function test_it_lists_nothing_for_a_non_member_and_refuses_an_employee(): void
    {
        $deactivated = UserMother::any('dora@example.com');
        OrganizationMother::addMember($this->organization, $deactivated, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05'));
        OrganizationMother::deactivateMember($this->organization, $deactivated, new \DateTimeImmutable('2026-11-01'));

        self::assertNull($this->list($deactivated->getId()->toRfc4122(), '2026-11-01', '2026-11-30'));
        self::assertNull($this->list(Uuid::v7()->toRfc4122(), '2026-11-01', '2026-11-30'));

        $this->expectException(IncomeManagementForbiddenException::class);
        $this->list($this->employee->getId()->toRfc4122(), '2026-11-01', '2026-11-30');
    }

    public function test_it_sums_a_period_per_payment_method_vat_rate_and_register(): void
    {
        $this->record($this->bar, '2026-11-01', [new PaymentAmount($this->cash, 100_00), new PaymentAmount($this->card, 200_00)], [new VatAmount('23', 246_00, 46_00), new VatAmount('zw', 10_00, 0)]);
        $this->record($this->kitchen, '2026-11-01', [new PaymentAmount($this->card, 108_00)], [new VatAmount('8', 108_00, 8_00)]);
        $this->record($this->bar, '2026-11-02', [new PaymentAmount($this->transfer, 50_00), new PaymentAmount($this->cash, 0)]);
        $this->record($this->bar, '2026-11-03', [new PaymentAmount($this->cash, 1)], [new VatAmount('23', 123_00, 23_00), new VatAmount('8', 54_00, 4_00)]);
        $this->recorded('2026-10-31');

        $summary = $this->summary($this->manager->getId()->toRfc4122(), '2026-11-01', '2026-11-30');

        self::assertEquals(new RevenueSummaryView('2026-11-01', '2026-11-30', 30, 3, 4, [
            new RevenueCurrencySummaryView(
                'PLN',
                458_01,
                4,
                [self::lineView($this->cash, 100_01), self::lineView($this->card, 308_00), self::lineView($this->transfer, 50_00)],
                [new RevenueVatLineView('23', 369_00, 69_00, 300_00), new RevenueVatLineView('8', 162_00, 12_00, 150_00), new RevenueVatLineView('zw', 10_00, 0, 10_00)],
                541_00,
                81_00,
                [
                    new RevenueRegisterTotalView($this->bar->getId()->toRfc4122(), 'Bar', 350_01, 3),
                    new RevenueRegisterTotalView($this->kitchen->getId()->toRfc4122(), 'Kitchen', 108_00, 1),
                ],
            ),
        ]), $summary);
    }

    public function test_it_sums_per_payment_method_in_the_organizations_order_with_current_names_and_deactivated_ones_last(): void
    {
        $this->record($this->bar, '2026-11-01', [new PaymentAmount($this->cash, 100), new PaymentAmount($this->card, 200), new PaymentAmount($this->voucher, 300)]);
        PaymentMethodOrder::apply([$this->cash, $this->card, $this->transfer, $this->voucher], [
            $this->voucher->getId()->toRfc4122(), $this->transfer->getId()->toRfc4122(), $this->card->getId()->toRfc4122(), $this->cash->getId()->toRfc4122(),
        ], $this->owner->getId(), MembershipRole::Owner);
        $this->card->rename('Card (terminal)', null, $this->owner->getId(), MembershipRole::Owner);
        $this->voucher->deactivate($this->owner->getId(), MembershipRole::Owner, $this->clock->now());

        $summary = $this->summary($this->owner->getId()->toRfc4122(), '2026-11-01', '2026-11-30');

        self::assertEquals(
            [self::lineView($this->card, 200), self::lineView($this->cash, 100), self::lineView($this->voucher, 300)],
            $summary?->currencies[0]->payments,
        );
        self::assertSame('Card (terminal)', $summary?->currencies[0]->payments[0]->paymentMethodName);
    }

    public function test_it_gives_zero_totals_in_the_organizations_currency_for_a_period_without_entries(): void
    {
        $summary = $this->summary($this->owner->getId()->toRfc4122(), '2026-02-01', '2026-02-28');

        self::assertEquals(new RevenueSummaryView('2026-02-01', '2026-02-28', 28, 0, 0, [
            new RevenueCurrencySummaryView('PLN', 0, 0, [], [], 0, 0, []),
        ]), $summary);
    }

    public function test_it_keeps_the_totals_of_each_currency_apart_with_the_organizations_current_currency_first(): void
    {
        $this->record($this->bar, '2026-11-01', [new PaymentAmount($this->cash, 100)], currency: 'PLN');
        $this->record($this->kitchen, '2026-11-01', [new PaymentAmount($this->cash, 7)], currency: 'USD');
        $this->record($this->bar, '2026-11-02', [new PaymentAmount($this->card, 30)], currency: 'EUR');
        $this->organization->changeSettings($this->owner->getId(), 'Bar', null, 'PL', 'USD', 'Europe/Warsaw');

        $summary = $this->summary($this->owner->getId()->toRfc4122(), '2026-11-01', '2026-11-30');

        self::assertNotNull($summary);
        self::assertSame(2, $summary->daysWithEntries);
        self::assertSame(3, $summary->entryCount);
        self::assertSame(
            [['USD', 7, 1, 'Kitchen'], ['EUR', 30, 1, 'Bar'], ['PLN', 100, 1, 'Bar']],
            array_map(
                static fn (RevenueCurrencySummaryView $c): array => [$c->currency, $c->total, $c->entryCount, $c->registers[0]->registerName],
                $summary->currencies,
            ),
        );
    }

    public function test_it_sums_up_to_a_year(): void
    {
        $this->recorded('2024-02-29');

        self::assertSame(366, $this->summary($this->owner->getId()->toRfc4122(), '2024-01-01', '2024-12-31')?->dayCount);

        $this->expectException(RevenuePeriodTooLongException::class);
        $this->summary($this->owner->getId()->toRfc4122(), '2025-01-01', '2026-01-02');
    }

    public function test_it_rejects_a_period_that_is_not_one(): void
    {
        try {
            $this->summary($this->owner->getId()->toRfc4122(), '2026-11-31', '2026-12-01');
            self::fail('A date that does not exist was taken.');
        } catch (InvalidBusinessDateException) {
        }

        $this->expectException(InvalidBusinessPeriodException::class);
        $this->list($this->owner->getId()->toRfc4122(), '2026-11-02', '2026-11-01');
    }

    public function test_it_sums_nothing_for_a_non_member_and_refuses_an_employee(): void
    {
        self::assertNull($this->summary(Uuid::v7()->toRfc4122(), '2026-11-01', '2026-11-30'));

        $this->expectException(IncomeManagementForbiddenException::class);
        $this->summary($this->employee->getId()->toRfc4122(), '2026-11-01', '2026-11-30');
    }

    /** @return list<RevenueEntryView>|null */
    private function list(string $actorId, string $from, string $to, ?string $registerId = null): ?array
    {
        return (new ListRevenueEntriesHandler($this->entries, $this->registers, $this->queryBus))(
            new ListRevenueEntriesQuery($this->organizationId(), $actorId, $from, $to, $registerId),
        );
    }

    private function summary(string $actorId, string $from, string $to): ?RevenueSummaryView
    {
        return (new GetRevenueSummaryHandler($this->entries, $this->queryBus))(
            new GetRevenueSummaryQuery($this->organizationId(), $actorId, $from, $to),
        );
    }

    /**
     * @param list<PaymentAmount> $payments
     * @param list<VatAmount>     $vat
     */
    private function record(Register $register, string $businessDate, array $payments, array $vat = [], string $currency = 'PLN'): RevenueEntry
    {
        $entry = RevenueEntry::record(
            Uuid::v7(),
            $register,
            BusinessDate::fromString($businessDate),
            $currency,
            'PL',
            $payments,
            $vat,
            null,
            $this->owner->getId(),
            MembershipRole::Owner,
            BusinessDate::fromString(self::TODAY_IN_WARSAW),
            new \DateTimeImmutable('2026-11-12 08:00:00'),
        );
        $this->entries->add($entry);

        return $entry;
    }
}
