<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Expenses\Application\Query\GetPurchaseInvoiceNumbers\GetPurchaseInvoiceNumbersQuery;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\AssignPurchasedItems\AssignPurchasedItemsCommand;
use App\Purchasing\Application\Command\AssignPurchasedItems\AssignPurchasedItemsHandler;
use App\Purchasing\Application\Command\ChangePurchasedItem\ChangePurchasedItemCommand;
use App\Purchasing\Application\Command\ChangePurchasedItem\ChangePurchasedItemHandler;
use App\Purchasing\Application\Query\GetPurchasedItem\GetPurchasedItemHandler;
use App\Purchasing\Application\Query\GetPurchasedItem\GetPurchasedItemQuery;
use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemView;
use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemViews;
use App\Purchasing\Application\Query\ListPurchasedItems\ListPurchasedItemsHandler;
use App\Purchasing\Application\Query\ListPurchasedItems\ListPurchasedItemsQuery;
use App\Purchasing\Application\Query\ListPurchasedItems\PurchasedItemPageView;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Exception\PurchasedItemNotFoundException;
use App\Purchasing\Domain\Exception\PurchasedItemProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * An organization with an owner, a manager and an employee, the products Jack Daniel's and Cola, and
 * one invoice (number FV/1/2026) with the items Jack Daniel's and Cola.
 */
#[CoversClass(ChangePurchasedItemHandler::class)]
#[CoversClass(AssignPurchasedItemsHandler::class)]
#[CoversClass(GetPurchasedItemHandler::class)]
#[CoversClass(ListPurchasedItemsHandler::class)]
#[CoversClass(PurchasedItemViews::class)]
#[CoversClass(PurchasedItemView::class)]
final class PurchasedItemHandlersTest extends TestCase
{
    private InMemoryProductRepository $products;
    private InMemoryPurchasedItemRepository $items;
    private StubQueryBus $queryBus;
    private RecordingEventPublisher $events;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private Product $jack;
    private Product $cola;
    private string $invoiceId;
    private PurchasedItem $jackItem;
    private PurchasedItem $colaItem;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->products = new InMemoryProductRepository();
        $this->items = new InMemoryPurchasedItemRepository();
        $this->events = new RecordingEventPublisher();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->jack = Product::create(Uuid::v7(), 'Jack Daniel\'s', null, ProductMeasure::Volume, null, false, null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->cola = Product::create(Uuid::v7(), 'Cola', null, ProductMeasure::Volume, null, false, null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->products->add($this->jack);
        $this->products->add($this->cola);

        $this->invoiceId = Uuid::v7()->toRfc4122();
        $this->items->addAll([
            $this->jackItem = $this->record(1, 'JACK DANIELS 0,7L'),
            $this->colaItem = $this->record(2, 'Cola 1l'),
        ]);

        $this->queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetPurchaseInvoiceNumbersQuery => array_fill_keys(array_intersect($query->invoiceIds, [$this->invoiceId]), 'FV/1/2026'),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_links_an_item_to_a_product_and_confirms_it(string $actor): void
    {
        $this->change($this->user($actor), $this->jackItem->getId()->toRfc4122(), $this->jack->getId()->toRfc4122(), '0.70');

        self::assertSame($this->jack, $this->jackItem->getProduct());
        self::assertSame(PurchasedItemStatus::Confirmed, $this->jackItem->getStatus());
        self::assertSame('0.7', $this->jackItem->getPackageSize());
        self::assertSame(1, $this->items->saveCallCount);
        self::assertSame(PurchasedItemStatus::Unassigned, $this->colaItem->getStatus());
    }

    public function test_it_takes_an_item_off_its_product_back_to_unassigned(): void
    {
        $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), $this->jack->getId()->toRfc4122(), '0.7');

        $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), null, '0.7');

        self::assertNull($this->jackItem->getProduct());
        self::assertSame(PurchasedItemStatus::Unassigned, $this->jackItem->getStatus());
        // A person's removal keeps the item out of matching, and confirms nothing to learn from (ADR 0042 §4).
        self::assertTrue($this->jackItem->isMatchingSkipped());
        self::assertCount(1, $this->events->events);
    }

    public function test_it_publishes_what_a_person_confirmed_so_that_aliases_are_learned(): void
    {
        $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), $this->jack->getId()->toRfc4122(), '0.7');
        $this->assign($this->owner, [$this->jackItem->getId()->toRfc4122(), $this->colaItem->getId()->toRfc4122()], $this->cola->getId()->toRfc4122(), null);

        self::assertCount(2, $this->events->events);
        [$first, $second] = $this->events->events;
        self::assertInstanceOf(PurchasedItemsConfirmed::class, $first);
        self::assertInstanceOf(PurchasedItemsConfirmed::class, $second);
        self::assertSame($this->organization->getId()->toRfc4122(), $first->organizationId);
        self::assertSame([$this->jackItem->getId()->toRfc4122()], $first->itemIds);
        self::assertEqualsCanonicalizing([$this->jackItem->getId()->toRfc4122(), $this->colaItem->getId()->toRfc4122()], $second->itemIds);
    }

    public function test_it_answers_not_found_for_an_unknown_item_or_product_and_changes_nothing(): void
    {
        $unknown = Uuid::v7()->toRfc4122();

        self::assertThrows(PurchasedItemNotFoundException::class, fn () => $this->change($this->owner, $unknown, $this->jack->getId()->toRfc4122(), '1'));
        self::assertThrows(PurchasedItemNotFoundException::class, fn () => $this->change($this->owner, 'not-a-uuid', $this->jack->getId()->toRfc4122(), '1'));
        self::assertThrows(PurchasedItemProductNotFoundException::class, fn () => $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), $unknown, '1'));
        self::assertThrows(PurchasedItemProductNotFoundException::class, fn () => $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), 'not-a-uuid', '1'));

        self::assertNull($this->jackItem->getProduct());
        self::assertSame(0, $this->items->saveCallCount);
    }

    public function test_it_refuses_an_invalid_package_size_when_changing_an_item(): void
    {
        self::assertThrows(InvalidPackageSizeException::class, fn () => $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), $this->jack->getId()->toRfc4122(), '-1'));

        self::assertNull($this->jackItem->getProduct());
        self::assertSame(0, $this->items->saveCallCount);
    }

    public function test_it_refuses_an_employee_when_changing_or_assigning_and_changes_nothing(): void
    {
        self::assertThrows(PurchasingManagementForbiddenException::class, fn () => $this->change($this->employee, $this->jackItem->getId()->toRfc4122(), $this->jack->getId()->toRfc4122(), '1'));
        self::assertThrows(PurchasingManagementForbiddenException::class, fn () => $this->assign($this->employee, [$this->jackItem->getId()->toRfc4122()], $this->jack->getId()->toRfc4122(), '1'));

        self::assertSame(PurchasedItemStatus::Unassigned, $this->jackItem->getStatus());
        self::assertSame(0, $this->items->saveCallCount);
    }

    public function test_it_refuses_someone_who_is_not_a_member(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->change(UserMother::any('stranger@example.com'), $this->jackItem->getId()->toRfc4122(), null, null);
    }

    #[DataProvider('managers')]
    public function test_it_assigns_several_items_to_a_product_in_one_save(string $actor): void
    {
        $this->assign($this->user($actor), [$this->jackItem->getId()->toRfc4122(), $this->colaItem->getId()->toRfc4122()], $this->cola->getId()->toRfc4122(), '1.5');

        foreach ([$this->jackItem, $this->colaItem] as $item) {
            self::assertSame($this->cola, $item->getProduct());
            self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());
            self::assertSame('1.5', $item->getPackageSize());
        }
        self::assertSame(1, $this->items->saveCallCount);
    }

    public function test_it_lets_each_item_keep_its_package_size_when_assigning_without_one(): void
    {
        $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), null, '0.7');

        $this->assign($this->owner, [$this->jackItem->getId()->toRfc4122(), $this->colaItem->getId()->toRfc4122()], $this->jack->getId()->toRfc4122(), null);

        self::assertSame('0.7', $this->jackItem->getPackageSize());
        self::assertNull($this->colaItem->getPackageSize());
        self::assertSame($this->jack, $this->colaItem->getProduct());
    }

    public function test_it_assigns_an_item_listed_twice_once(): void
    {
        $id = $this->jackItem->getId()->toRfc4122();

        $this->assign($this->owner, [$id, $id], $this->jack->getId()->toRfc4122(), '1');

        self::assertSame($this->jack, $this->jackItem->getProduct());
        self::assertSame(1, $this->items->saveCallCount);
    }

    public function test_it_assigns_nothing_when_one_item_or_the_product_is_not_found(): void
    {
        $unknown = Uuid::v7()->toRfc4122();
        $jackItemId = $this->jackItem->getId()->toRfc4122();

        self::assertThrows(PurchasedItemNotFoundException::class, fn () => $this->assign($this->owner, [$jackItemId, $unknown], $this->jack->getId()->toRfc4122(), '1'));
        self::assertThrows(PurchasedItemNotFoundException::class, fn () => $this->assign($this->owner, [$jackItemId, 'not-a-uuid'], $this->jack->getId()->toRfc4122(), '1'));
        self::assertThrows(PurchasedItemProductNotFoundException::class, fn () => $this->assign($this->owner, [$jackItemId], $unknown, '1'));
        self::assertThrows(InvalidPackageSizeException::class, fn () => $this->assign($this->owner, [$jackItemId, $this->colaItem->getId()->toRfc4122()], $this->jack->getId()->toRfc4122(), '0'));

        self::assertNull($this->jackItem->getProduct());
        self::assertNull($this->colaItem->getProduct());
        self::assertSame(0, $this->items->saveCallCount);
    }

    public function test_it_refuses_to_assign_more_items_than_the_limit(): void
    {
        $ids = array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(0, AssignPurchasedItemsCommand::MAX_ITEMS));

        $this->expectException(\InvalidArgumentException::class);

        $this->assign($this->owner, $ids, $this->jack->getId()->toRfc4122(), null);
    }

    public function test_it_shows_an_item_with_its_invoice_number_and_product(): void
    {
        $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), $this->jack->getId()->toRfc4122(), '0.7');

        $view = $this->get($this->manager, $this->jackItem->getId()->toRfc4122());

        self::assertNotNull($view);
        self::assertSame(
            [$this->jackItem->getId()->toRfc4122(), $this->invoiceId, 'FV/1/2026', '2026-11-10', 'JACK DANIELS 0,7L', $this->jack->getId()->toRfc4122(), 'Jack Daniel\'s', PurchasedItemStatus::Confirmed, '0.7'],
            [$view->id, $view->invoiceId, $view->invoiceNumber, $view->invoiceIssueDate, $view->name, $view->productId, $view->productName, $view->status, $view->packageSize],
        );
        self::assertSame(['PLN', 'regular', 'Hurtownia Napojów', '5265877635', 1, 'szt.', '6', 48_780, 60_000, 11_220, '23'], [
            $view->currency, $view->invoiceKind, $view->sellerName, $view->sellerTaxId, $view->position, $view->unit, $view->quantity, $view->netAmount, $view->grossAmount, $view->vatAmount, $view->vatRate,
        ]);
    }

    public function test_it_shows_no_invoice_number_for_an_invoice_without_one_and_nothing_for_an_unknown_item(): void
    {
        $receipt = $this->record(1, 'Receipt item', Uuid::v7()->toRfc4122());
        $this->items->addAll([$receipt]);

        self::assertNull($this->get($this->owner, $receipt->getId()->toRfc4122())?->invoiceNumber);
        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));
        self::assertNull($this->get(UserMother::any('stranger@example.com'), $this->jackItem->getId()->toRfc4122()));
    }

    public function test_it_refuses_an_employee_when_showing_or_listing(): void
    {
        self::assertThrows(PurchasingManagementForbiddenException::class, fn () => $this->get($this->employee, $this->jackItem->getId()->toRfc4122()));
        self::assertThrows(PurchasingManagementForbiddenException::class, fn () => $this->list(new ListPurchasedItemsQuery($this->organizationId(), $this->employee->getId()->toRfc4122())));
    }

    public function test_it_lists_a_page_of_items_with_the_total_and_asks_expenses_once(): void
    {
        $page = $this->list(new ListPurchasedItemsQuery($this->organizationId(), $this->owner->getId()->toRfc4122(), pageSize: 1, page: 2));

        self::assertSame([2, 1, 2], [$page?->total, $page?->pageSize, $page?->page]);
        self::assertSame(['Cola 1l'], array_map(static fn (PurchasedItemView $view): string => $view->name, $page->items ?? []));
        self::assertSame('FV/1/2026', $page?->items[0]->invoiceNumber);
        self::assertCount(1, array_filter($this->queryBus->queries, static fn (QueryInterface $query): bool => $query instanceof GetPurchaseInvoiceNumbersQuery));
    }

    public function test_it_limits_the_page_size_and_starts_at_the_first_page(): void
    {
        $page = $this->list(new ListPurchasedItemsQuery($this->organizationId(), $this->owner->getId()->toRfc4122(), page: -3, pageSize: 10_000));

        self::assertSame([1, ListPurchasedItemsQuery::MAX_PAGE_SIZE, 2], [$page?->page, $page?->pageSize, \count($page->items ?? [])]);
    }

    public function test_it_filters_by_status_and_product(): void
    {
        $this->change($this->owner, $this->jackItem->getId()->toRfc4122(), $this->jack->getId()->toRfc4122(), '0.7');
        $ownerId = $this->owner->getId()->toRfc4122();

        $confirmed = $this->list(new ListPurchasedItemsQuery($this->organizationId(), $ownerId, statuses: ['confirmed', 'suggested']));
        $queue = $this->list(new ListPurchasedItemsQuery($this->organizationId(), $ownerId, statuses: ['unassigned', 'suggested']));
        $ofJack = $this->list(new ListPurchasedItemsQuery($this->organizationId(), $ownerId, productId: $this->jack->getId()->toRfc4122()));
        $ofNobody = $this->list(new ListPurchasedItemsQuery($this->organizationId(), $ownerId, productId: 'not-a-uuid'));

        self::assertSame(['JACK DANIELS 0,7L'], array_map(static fn (PurchasedItemView $view): string => $view->name, $confirmed->items ?? []));
        self::assertSame(['Cola 1l'], array_map(static fn (PurchasedItemView $view): string => $view->name, $queue->items ?? []));
        self::assertSame([1, 1, 0], [$ofJack?->total, $queue?->total, $ofNobody?->total]);
    }

    public function test_it_refuses_an_unknown_status(): void
    {
        $this->expectException(\ValueError::class);

        $this->list(new ListPurchasedItemsQuery($this->organizationId(), $this->owner->getId()->toRfc4122(), statuses: ['finished']));
    }

    private function record(int $index, string $name, ?string $invoiceId = null): PurchasedItem
    {
        return PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::fromString($invoiceId ?? $this->invoiceId), $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
            $index, $name, null, null, 'szt.', '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    private function change(User $actor, string $itemId, ?string $productId, ?string $packageSize): void
    {
        (new ChangePurchasedItemHandler($this->items, $this->products, $this->queryBus, $this->events))(
            new ChangePurchasedItemCommand($this->organizationId(), $actor->getId()->toRfc4122(), $itemId, $productId, $packageSize),
        );
    }

    /** @param list<string> $itemIds */
    private function assign(User $actor, array $itemIds, string $productId, ?string $packageSize): void
    {
        (new AssignPurchasedItemsHandler($this->items, $this->products, $this->queryBus, $this->events))(
            new AssignPurchasedItemsCommand($this->organizationId(), $actor->getId()->toRfc4122(), $itemIds, $productId, $packageSize),
        );
    }

    private function get(User $actor, string $itemId): ?PurchasedItemView
    {
        return (new GetPurchasedItemHandler($this->items, new PurchasedItemViews($this->queryBus), $this->queryBus))(
            new GetPurchasedItemQuery($this->organizationId(), $actor->getId()->toRfc4122(), $itemId),
        );
    }

    private function list(ListPurchasedItemsQuery $query): ?PurchasedItemPageView
    {
        return (new ListPurchasedItemsHandler($this->items, new PurchasedItemViews($this->queryBus), $this->queryBus))($query);
    }

    /** @param class-string<\Throwable> $exception */
    private static function assertThrows(string $exception, \Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);

            return;
        }

        self::fail(\sprintf('%s expected.', $exception));
    }
}
