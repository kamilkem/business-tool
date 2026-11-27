<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Expenses\Application\Query\GetPurchaseInvoiceNumbers\GetPurchaseInvoiceNumbersQuery;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Query\GetProductPriceHistory\GetProductPriceHistoryHandler;
use App\Purchasing\Application\Query\GetProductPriceHistory\GetProductPriceHistoryQuery;
use App\Purchasing\Application\Query\GetProductPriceHistory\ProductPriceHistoryView;
use App\Purchasing\Application\Query\GetPurchaseAnalysis\GetPurchaseAnalysisHandler;
use App\Purchasing\Application\Query\GetPurchaseAnalysis\GetPurchaseAnalysisQuery;
use App\Purchasing\Application\Query\GetPurchaseAnalysis\PurchaseAnalysisView;
use App\Purchasing\Domain\Analysis\AnalysisAmounts;
use App\Purchasing\Domain\Analysis\PriceChange;
use App\Purchasing\Domain\Analysis\PricePoint;
use App\Purchasing\Domain\Analysis\ProductSpend;
use App\Purchasing\Domain\Analysis\PurchaseAnalysisData;
use App\Purchasing\Domain\Exception\PurchaseAnalysisPeriodTooLongException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The handlers of the overview and the price history with a fake analysis (the SQL is tested against
 * the database): who may ask, the period rules, the organization's currency and what they add up.
 * An organization (PLN) with an owner, a manager, an employee and a user who is not a member.
 */
#[CoversClass(GetPurchaseAnalysisHandler::class)]
#[CoversClass(GetProductPriceHistoryHandler::class)]
final class PurchaseAnalysisHandlersTest extends TestCase
{
    private InMemoryPurchaseAnalysisRepository $analysis;
    private InMemoryProductRepository $products;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $outsider;
    private Product $jack;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->analysis = new InMemoryPurchaseAnalysisRepository();
        $this->products = new InMemoryProductRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->outsider = UserMother::any('otto@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->jack = Product::create(Uuid::v7(), 'Jack Daniel\'s', null, ProductMeasure::Volume, '0.5', false, null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->products->add($this->jack);

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof GetPurchaseInvoiceNumbersQuery => array_fill_keys($query->invoiceIds, 'FV/1/2026'),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    // ---- Overview ----

    #[DataProvider('managers')]
    public function test_it_asks_for_the_period_in_the_organizations_currency(string $actor): void
    {
        $view = $this->overview($this->{$actor}, '2026-11-01', '2026-11-30');

        self::assertNotNull($view);
        self::assertSame(['2026-11-01', '2026-11-30', $this->organization->getCurrency()], [$view->from, $view->to, $view->currency]);
        self::assertCount(1, $this->analysis->overviews);
        [$period, $currency] = $this->analysis->overviews[0];
        self::assertSame(['2026-11-01', '2026-11-30', $this->organization->getCurrency()], [$period->first->toString(), $period->last->toString(), $currency]);
    }

    public function test_it_adds_up_the_products_the_uncategorized_ones_and_the_items_without_a_product(): void
    {
        $category = Uuid::v7()->toRfc4122();
        $this->analysis->data = new PurchaseAnalysisData(
            [],
            [
                self::product('Czynsz', null, 760_000, 934_800),
                self::product('Jack Daniel\'s', $category, 12_000, 22_760),
                self::product('Kawa', null, -300, -369),
            ],
            new AnalysisAmounts(2_000, 2_460),
            3,
            1,
            2,
            0,
            4,
        );

        $view = $this->overview($this->owner, '2026-11-01', '2026-11-30');

        self::assertNotNull($view);
        self::assertSame([773_700, 959_651], [$view->total->net, $view->total->gross], 'All products and the items without one.');
        self::assertSame([759_700, 934_431, 2], [$view->uncategorizedSpend->net, $view->uncategorizedSpend->gross, $view->uncategorizedProducts]);
        self::assertSame($this->analysis->data, $view->analysis);
    }

    public function test_it_answers_an_empty_overview_with_zero_totals(): void
    {
        $view = $this->overview($this->owner, '2026-11-01', '2026-11-30');

        self::assertNotNull($view);
        self::assertSame([0, 0, 0, 0, 0], [$view->total->net, $view->total->gross, $view->uncategorizedSpend->net, $view->uncategorizedSpend->gross, $view->uncategorizedProducts]);
    }

    public function test_it_accepts_a_period_of_a_whole_leap_year_and_refuses_a_longer_one(): void
    {
        self::assertNotNull($this->overview($this->owner, '2028-01-01', '2028-12-31'));

        try {
            $this->overview($this->owner, '2026-01-01', '2027-01-02');
            self::fail('The 366-day limit should hold.');
        } catch (PurchaseAnalysisPeriodTooLongException $exception) {
            self::assertSame(['from' => '2026-01-01', 'to' => '2027-01-02', 'days' => 367, 'maxDays' => 366], $exception->errorParameters());
        }
        self::assertCount(1, $this->analysis->overviews, 'Nothing is aggregated for a refused period.');
    }

    public function test_it_refuses_an_invalid_period(): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);

        $this->overview($this->owner, '2026-11-30', '2026-11-01');
    }

    public function test_it_refuses_a_date_that_does_not_exist(): void
    {
        $this->expectException(InvalidBusinessDateException::class);

        $this->overview($this->owner, '2026-02-30', '2026-03-01');
    }

    public function test_it_refuses_an_employee_the_overview(): void
    {
        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->overview($this->employee, '2026-11-01', '2026-11-30');
    }

    public function test_it_answers_nothing_to_a_user_who_is_not_a_member(): void
    {
        self::assertNull($this->overview($this->outsider, '2026-11-01', '2026-11-30'));
        self::assertSame([], $this->analysis->overviews);
    }

    // ---- Price history ----

    #[DataProvider('managers')]
    public function test_it_lists_the_price_points_with_their_invoice_numbers(string $actor): void
    {
        $this->analysis->points = [self::point('2026-11-05', $first = Uuid::v7()->toRfc4122()), self::point('2026-11-12', $first), self::point('2026-11-20', $second = Uuid::v7()->toRfc4122())];

        $view = $this->history($this->{$actor}, $this->jack->getId()->toRfc4122(), '2026-11-01', '2026-11-30');

        self::assertNotNull($view);
        self::assertSame([$this->jack->getId()->toRfc4122(), 'Jack Daniel\'s', ProductMeasure::Volume, '0.5', $this->organization->getCurrency(), '2026-11-01', '2026-11-30'], [
            $view->productId, $view->productName, $view->measure, $view->referenceQuantity, $view->currency, $view->from, $view->to,
        ]);
        self::assertCount(3, $view->points);
        self::assertSame(['FV/1/2026', 'FV/1/2026', 'FV/1/2026'], array_map(static fn ($point): ?string => $point->invoiceNumber, $view->points));
        self::assertSame([[$this->jack->getId()->toRfc4122(), $this->organization->getCurrency(), '2026-11-01', '2026-11-30']], $this->analysis->histories);
        $numberQueries = array_values(array_filter($this->queryBus->queries, static fn (QueryInterface $query): bool => $query instanceof GetPurchaseInvoiceNumbersQuery));
        self::assertCount(1, $numberQueries, 'The numbers of the invoices are asked for at once.');
        self::assertSame([$first, $second], $numberQueries[0]->invoiceIds);
    }

    public function test_it_asks_for_the_invoice_numbers_in_chunks_the_query_allows(): void
    {
        $this->analysis->points = array_map(static fn (int $day): PricePoint => self::point('2026-11-05', Uuid::v7()->toRfc4122()), range(1, GetPurchaseInvoiceNumbersQuery::MAX_INVOICES + 1));

        $view = $this->history($this->owner, $this->jack->getId()->toRfc4122());

        self::assertNotNull($view);
        self::assertCount(GetPurchaseInvoiceNumbersQuery::MAX_INVOICES + 1, $view->points);
        $numberQueries = array_values(array_filter($this->queryBus->queries, static fn (QueryInterface $query): bool => $query instanceof GetPurchaseInvoiceNumbersQuery));
        self::assertSame([GetPurchaseInvoiceNumbersQuery::MAX_INVOICES, 1], array_map(static fn (GetPurchaseInvoiceNumbersQuery $query): int => \count($query->invoiceIds), $numberQueries));
    }

    public function test_it_leaves_the_period_open_when_it_is_not_given_and_asks_for_no_numbers_without_points(): void
    {
        $view = $this->history($this->owner, $this->jack->getId()->toRfc4122());

        self::assertNotNull($view);
        self::assertSame([null, null, []], [$view->from, $view->to, $view->points]);
        self::assertSame([[$this->jack->getId()->toRfc4122(), $this->organization->getCurrency(), null, null]], $this->analysis->histories);
        self::assertSame([], array_filter($this->queryBus->queries, static fn (QueryInterface $query): bool => $query instanceof GetPurchaseInvoiceNumbersQuery));
    }

    public function test_it_finds_no_history_of_a_product_that_does_not_exist(): void
    {
        self::assertNull($this->history($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->history($this->owner, 'not-a-uuid'));
        self::assertSame([], $this->analysis->histories);
    }

    public function test_it_refuses_an_employee_the_price_history(): void
    {
        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->history($this->employee, $this->jack->getId()->toRfc4122());
    }

    public function test_it_finds_no_price_history_for_a_user_who_is_not_a_member(): void
    {
        self::assertNull($this->history($this->outsider, $this->jack->getId()->toRfc4122()));
    }

    public function test_it_refuses_a_history_date_that_does_not_exist(): void
    {
        $this->expectException(InvalidBusinessDateException::class);

        $this->history($this->owner, $this->jack->getId()->toRfc4122(), '2026-13-01');
    }

    private function overview(User $actor, string $from, string $to): ?PurchaseAnalysisView
    {
        return (new GetPurchaseAnalysisHandler($this->analysis, $this->queryBus))(new GetPurchaseAnalysisQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $from, $to));
    }

    private function history(User $actor, string $productId, ?string $from = null, ?string $to = null): ?ProductPriceHistoryView
    {
        return (new GetProductPriceHistoryHandler($this->products, $this->analysis, $this->queryBus))(new GetProductPriceHistoryQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $productId, $from, $to));
    }

    private static function product(string $name, ?string $categoryId, int $net, int $gross): ProductSpend
    {
        return new ProductSpend(Uuid::v7()->toRfc4122(), $name, $categoryId, ProductMeasure::None, null, new AnalysisAmounts($net, $gross), null, null, null, null, null, null);
    }

    private static function point(string $date, string $invoiceId): PricePoint
    {
        return new PricePoint(Uuid::v7()->toRfc4122(), $date, '5265877635', 'Hurtownia', $invoiceId, 'Jack', '1', '0.7', null, new PriceChange(100, null, null), null);
    }
}
