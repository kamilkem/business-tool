<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryHandler;
use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryQuery;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Application\Query\GetRevenueEntry\RevenueVatLineView;
use App\Income\Application\Query\ListVatRates\ListVatRatesHandler;
use App\Income\Application\Query\ListVatRates\ListVatRatesQuery;
use App\Income\Application\Query\ListVatRates\VatRateView;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetRevenueEntryHandler::class)]
#[CoversClass(RevenueEntryView::class)]
#[CoversClass(ListVatRatesHandler::class)]
final class RevenueEntryQueriesTest extends IncomeHandlerTestCase
{
    public function test_it_returns_an_entry_with_its_lines_and_totals(): void
    {
        $entry = $this->recorded('2026-11-10');
        $entry->change(
            [new PaymentAmount($this->transfer, 50_000), new PaymentAmount($this->cash, 120_000)],
            [new VatAmount('zw', 1_000, 0), new VatAmount('23', 100_000, 18_699), new VatAmount('8', 20_000, 1_481)],
            'Event',
            'PL',
            $this->manager->getId(),
            MembershipRole::Manager,
            new \DateTimeImmutable('2026-11-11 10:00:00'),
        );

        $view = $this->get($this->manager->getId()->toRfc4122(), $entry->getId()->toRfc4122());

        self::assertEquals(new RevenueEntryView(
            $entry->getId()->toRfc4122(),
            $this->bar->getId()->toRfc4122(),
            'Bar',
            '2026-11-10',
            'PLN',
            [self::lineView($this->cash, 120_000), self::lineView($this->transfer, 50_000)],
            [new RevenueVatLineView('23', 100_000, 18_699, 81_301), new RevenueVatLineView('8', 20_000, 1_481, 18_519), new RevenueVatLineView('zw', 1_000, 0, 1_000)],
            170_000,
            121_000,
            20_180,
            'Event',
            null,
            $this->owner->getId()->toRfc4122(),
            new \DateTimeImmutable('2026-11-12 08:00:00'),
            $this->manager->getId()->toRfc4122(),
            new \DateTimeImmutable('2026-11-11 10:00:00'),
        ), $view);
    }

    public function test_it_returns_null_for_an_unknown_entry_or_a_non_member(): void
    {
        $entry = $this->recorded('2026-11-10');
        $deactivated = UserMother::any('dora@example.com');
        OrganizationMother::addMember($this->organization, $deactivated, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05'));
        OrganizationMother::deactivateMember($this->organization, $deactivated, new \DateTimeImmutable('2026-11-01'));

        self::assertNull($this->get($this->owner->getId()->toRfc4122(), Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner->getId()->toRfc4122(), 'nope'));
        self::assertNull($this->get($deactivated->getId()->toRfc4122(), $entry->getId()->toRfc4122()));
        self::assertNull($this->get(Uuid::v7()->toRfc4122(), $entry->getId()->toRfc4122()));
    }

    public function test_it_refuses_an_entry_to_an_employee(): void
    {
        $entry = $this->recorded('2026-11-10');

        $this->expectException(IncomeManagementForbiddenException::class);

        $this->get($this->employee->getId()->toRfc4122(), $entry->getId()->toRfc4122());
    }

    public function test_it_lists_the_vat_rates_of_the_organizations_country(): void
    {
        self::assertEquals(
            [new VatRateView('23', false), new VatRateView('8', false), new VatRateView('5', false), new VatRateView('0', true), new VatRateView('zw', true)],
            $this->vatRates($this->owner->getId()->toRfc4122()),
        );

        $this->organization->changeSettings($this->owner->getId(), 'Corner Pub', null, 'DE', 'EUR', 'Europe/Berlin');
        self::assertSame([], $this->vatRates($this->manager->getId()->toRfc4122()));
        self::assertNull($this->vatRates(Uuid::v7()->toRfc4122()));
    }

    public function test_it_refuses_the_vat_rates_to_an_employee(): void
    {
        $this->expectException(IncomeManagementForbiddenException::class);

        $this->vatRates($this->employee->getId()->toRfc4122());
    }

    private function get(string $actorId, string $entryId): ?RevenueEntryView
    {
        return (new GetRevenueEntryHandler($this->entries, $this->queryBus))(new GetRevenueEntryQuery($this->organizationId(), $actorId, $entryId));
    }

    /** @return list<VatRateView>|null */
    private function vatRates(string $actorId): ?array
    {
        return (new ListVatRatesHandler($this->queryBus))(new ListVatRatesQuery($this->organizationId(), $actorId));
    }
}
