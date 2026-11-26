<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\AcceptProductProposal\AcceptProductProposalCommand;
use App\Purchasing\Application\Command\AcceptProductProposal\AcceptProductProposalHandler;
use App\Purchasing\Application\Command\AssignProductProposal\AssignProductProposalCommand;
use App\Purchasing\Application\Command\AssignProductProposal\AssignProductProposalHandler;
use App\Purchasing\Application\Command\DismissProductProposal\DismissProductProposalCommand;
use App\Purchasing\Application\Command\DismissProductProposal\DismissProductProposalHandler;
use App\Purchasing\Application\Query\ListProductProposals\ListProductProposalsHandler;
use App\Purchasing\Application\Query\ListProductProposals\ListProductProposalsQuery;
use App\Purchasing\Application\Query\ListProductProposals\ProductProposalView;
use App\Purchasing\Domain\Event\ProductCreated;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Exception\ProductProposalNotFoundException;
use App\Purchasing\Domain\Exception\PurchasedItemProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Exception\UnknownProductCategoryException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * The owner's decisions on the products the model proposes (ADR 0044): create and assign, assign to a
 * product that exists, dismiss, and the list that groups the proposals. An organization with an owner, a
 * manager and an employee, the category Whisky, the product Cola, and four unmatched items that propose
 * Jack Daniel's (three spellings) and Tyskie.
 */
#[CoversClass(AcceptProductProposalHandler::class)]
#[CoversClass(AssignProductProposalHandler::class)]
#[CoversClass(DismissProductProposalHandler::class)]
#[CoversClass(ListProductProposalsHandler::class)]
#[CoversClass(ProductProposalView::class)]
final class ProductProposalHandlersTest extends TestCase
{
    private InMemoryPurchasedItemRepository $items;
    private InMemoryProductRepository $products;
    private InMemoryProductCategoryRepository $categories;
    private RecordingEventPublisher $events;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private ProductCategory $whisky;
    private Product $cola;
    /** @var list<PurchasedItem> */
    private array $jackItems;
    private PurchasedItem $tyskieItem;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->items = new InMemoryPurchasedItemRepository();
        $this->products = new InMemoryProductRepository();
        $this->categories = new InMemoryProductCategoryRepository();
        $this->events = new RecordingEventPublisher();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->whisky = ProductCategory::create(Uuid::v7(), 'Whisky', null, null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->categories->add($this->whisky);
        $this->cola = Product::create(Uuid::v7(), 'Cola', null, ProductMeasure::Volume, null, false, null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->products->add($this->cola);

        $this->jackItems = [
            $this->item(1, 'JACK DANIELS 0,7L 40%', 'szt.', 'Jack Daniel\'s', $this->whisky),
            $this->item(2, 'Whiskey Jack Daniel\'s Old No.7 700ml', 'szt.', 'Jack Daniels', $this->whisky),
            $this->item(3, 'J.DANIELS 1L', 'szt.', 'jack daniel\'s', null),
        ];
        $this->tyskieItem = $this->item(4, 'TYSKIE GOLD 0,5L', 'szt.', 'Tyskie', null, ProductMeasure::Volume);

        $this->queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    public function test_it_groups_the_spellings_of_a_proposal_into_one_row_for_an_owner_or_manager(): void
    {
        foreach (['owner', 'manager'] as $actor) {
            $views = $this->list($this->user($actor));

            self::assertNotNull($views);
            self::assertCount(2, $views);
            self::assertSame(['jack-daniels', 'Jack Daniel\'s', ProductMeasure::Volume, $this->whisky->getId()->toRfc4122(), 3, null], [$views[0]->id, $views[0]->name, $views[0]->measure, $views[0]->categoryId, $views[0]->itemCount, $views[0]->existingProductId]);
            self::assertSame(['J.DANIELS 1L', 'JACK DANIELS 0,7L 40%', 'Whiskey Jack Daniel\'s Old No.7 700ml'], $views[0]->exampleNames);
            self::assertSame(['tyskie', 1], [$views[1]->id, $views[1]->itemCount]);
        }
    }

    public function test_it_shows_the_product_that_has_the_proposed_name_by_now(): void
    {
        $this->products->add(Product::create(Uuid::v7(), 'TYSKIE', null, ProductMeasure::Volume, null, false, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-20 10:00:00')));

        $views = $this->list($this->owner);

        self::assertNotNull($views);
        self::assertNotNull($views[1]->existingProductId, 'The proposal is that product\'s other name.');
    }

    public function test_it_lists_no_proposal_for_an_item_a_person_decided_on(): void
    {
        $this->jackItems[0]->link($this->cola, '0.7', $this->owner->getId(), MembershipRole::Owner);
        $this->jackItems[1]->link(null, null, $this->owner->getId(), MembershipRole::Owner);

        $views = $this->list($this->owner);

        self::assertNotNull($views);
        self::assertSame(1, $views[0]->itemCount);
    }

    public function test_it_refuses_an_employee_and_a_stranger_the_list(): void
    {
        try {
            $this->list($this->employee);
            self::fail('Employees do not see proposals.');
        } catch (PurchasingManagementForbiddenException) {
        }

        self::assertNull($this->list(UserMother::any('stranger@example.com')));
    }

    #[DataProvider('managers')]
    public function test_it_creates_the_product_and_confirms_every_item_of_the_proposal_in_one_flush(string $actor): void
    {
        $productId = Uuid::v7()->toRfc4122();

        $this->accept($this->user($actor), 'jack daniels', $productId, '  Jack Daniel\'s ', ProductMeasure::Volume, $this->whisky->getId()->toRfc4122());

        $product = $this->products->findById(Uuid::fromString($productId));
        self::assertNotNull($product);
        self::assertSame(['Jack Daniel\'s', ProductMeasure::Volume, '1', $this->whisky], [$product->getName(), $product->getMeasure(), $product->getReferenceQuantity(), $product->getCategory()]);
        foreach ($this->jackItems as $item) {
            self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());
            self::assertSame($product, $item->getProduct());
            self::assertFalse($item->hasProposal());
        }
        // The package sizes the names state strongly are kept, so the aliases remember them: 0.7 L, 0.7 L, 1 L.
        self::assertSame(['0.7', '0.7', '1'], array_map(static fn (PurchasedItem $item): ?string => $item->getPackageSize(), $this->jackItems));
        self::assertSame(PurchasedItemStatus::Unassigned, $this->tyskieItem->getStatus());
        // The aliases are learned and the rest matched again by the events (ADR 0042 §7, ADR 0044).
        /** @var non-empty-list<string> $ids */
        $ids = array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $this->jackItems);
        self::assertEquals([new ProductCreated($this->organizationId(), $productId), new PurchasedItemsConfirmed($this->organizationId(), $ids)], $this->events->events);
    }

    public function test_it_creates_the_product_with_what_the_person_edited_not_with_the_proposal(): void
    {
        $productId = Uuid::v7()->toRfc4122();

        $this->accept($this->owner, 'tyskie', $productId, 'Tyskie Gold', ProductMeasure::Count, null, '0.5');

        $product = $this->products->findById(Uuid::fromString($productId));
        self::assertNotNull($product);
        self::assertSame(['Tyskie Gold', ProductMeasure::Count, '0.5'], [$product->getName(), $product->getMeasure(), $product->getReferenceQuantity()]);
        self::assertNull($product->getCategory());
        self::assertSame($product, $this->tyskieItem->getProduct());
    }

    public function test_it_changes_nothing_when_the_name_is_taken_or_the_category_unknown(): void
    {
        foreach ([['cola', null, ProductNameTakenException::class], ['New', Uuid::v7()->toRfc4122(), UnknownProductCategoryException::class]] as [$name, $categoryId, $exception]) {
            try {
                $this->accept($this->owner, 'jack daniels', Uuid::v7()->toRfc4122(), $name, ProductMeasure::Volume, $categoryId);
                self::fail('Refused.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($exception, $e);
            }
        }

        self::assertSame([$this->cola], $this->products->search(null, null));
        self::assertSame([], $this->events->events);
        self::assertSame(PurchasedItemStatus::Unassigned, $this->jackItems[0]->getStatus());
    }

    public function test_it_refuses_an_employee_and_an_unknown_proposal_and_creates_no_product(): void
    {
        try {
            $this->accept($this->employee, 'jack daniels', Uuid::v7()->toRfc4122(), 'Jack', ProductMeasure::Volume, null);
            self::fail('Employees create no products.');
        } catch (PurchasingManagementForbiddenException) {
        }
        try {
            $this->accept($this->owner, 'no such proposal', Uuid::v7()->toRfc4122(), 'Jack', ProductMeasure::Volume, null);
            self::fail('No such proposal.');
        } catch (ProductProposalNotFoundException) {
        }

        self::assertSame([$this->cola], $this->products->search(null, null));
        self::assertSame([], $this->events->events);
    }

    public function test_it_links_the_items_to_a_product_that_exists_and_creates_none(): void
    {
        $this->assign($this->manager, 'tyskie', $this->cola->getId()->toRfc4122());

        self::assertSame($this->cola, $this->tyskieItem->getProduct());
        self::assertSame(PurchasedItemStatus::Confirmed, $this->tyskieItem->getStatus());
        self::assertFalse($this->tyskieItem->hasProposal());
        self::assertSame([$this->cola], $this->products->search(null, null));
        self::assertSame(1, $this->items->saveCallCount);
        self::assertEquals([new PurchasedItemsConfirmed($this->organizationId(), [$this->tyskieItem->getId()->toRfc4122()])], $this->events->events);
    }

    public function test_it_refuses_a_product_that_is_not_the_organizations_and_an_employee_when_assigning(): void
    {
        try {
            $this->assign($this->owner, 'tyskie', Uuid::v7()->toRfc4122());
            self::fail('No such product.');
        } catch (PurchasedItemProductNotFoundException) {
        }
        try {
            $this->assign($this->employee, 'tyskie', $this->cola->getId()->toRfc4122());
            self::fail('Employees decide nothing.');
        } catch (PurchasingManagementForbiddenException) {
        }

        self::assertNull($this->tyskieItem->getProduct());
    }

    #[DataProvider('managers')]
    public function test_it_dismisses_a_proposal_and_keeps_the_items_unassigned(string $actor): void
    {
        $this->dismiss($this->user($actor), 'jack daniels');

        foreach ($this->jackItems as $item) {
            self::assertFalse($item->hasProposal());
            self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
            self::assertNull($item->getProduct());
            self::assertFalse($item->isMatchingSkipped(), 'Dismissed: not proposed again, but still open to matching.');
            $item->propose('Jack Daniel\'s', ProductMeasure::Volume, null);
            self::assertFalse($item->hasProposal(), 'The same name is not proposed again for it.');
        }
        self::assertTrue($this->tyskieItem->hasProposal());
        self::assertSame([], $this->events->events);
        self::assertCount(1, $this->list($this->owner) ?? []);
    }

    public function test_it_refuses_an_employee_and_an_unknown_proposal_when_dismissing(): void
    {
        try {
            $this->dismiss($this->employee, 'tyskie');
            self::fail('Employees decide nothing.');
        } catch (PurchasingManagementForbiddenException) {
        }
        try {
            $this->dismiss($this->owner, 'nothing');
            self::fail('No such proposal.');
        } catch (ProductProposalNotFoundException) {
        }

        self::assertTrue($this->tyskieItem->hasProposal());
    }

    /** @return list<ProductProposalView>|null */
    private function list(User $actor): ?array
    {
        $handler = new ListProductProposalsHandler($this->items, $this->products, $this->queryBus);

        /** @var list<ProductProposalView>|null */
        return $handler(new ListProductProposalsQuery($this->organizationId(), $actor->getId()->toRfc4122()));
    }

    private function accept(User $actor, string $key, string $productId, string $name, ProductMeasure $measure, ?string $categoryId, ?string $referenceQuantity = null): void
    {
        (new AcceptProductProposalHandler($this->items, $this->products, $this->categories, $this->queryBus, $this->events, new MockClock('2026-11-26 10:00:00')))(
            new AcceptProductProposalCommand($this->organizationId(), $actor->getId()->toRfc4122(), $key, $productId, $name, $measure->value, $categoryId, $referenceQuantity),
        );
    }

    private function assign(User $actor, string $key, string $productId): void
    {
        (new AssignProductProposalHandler($this->items, $this->products, $this->queryBus, $this->events))(
            new AssignProductProposalCommand($this->organizationId(), $actor->getId()->toRfc4122(), $key, $productId),
        );
    }

    private function dismiss(User $actor, string $key): void
    {
        (new DismissProductProposalHandler($this->items, $this->queryBus))(
            new DismissProductProposalCommand($this->organizationId(), $actor->getId()->toRfc4122(), $key),
        );
    }

    private function item(int $index, string $name, string $unit, string $proposedName, ?ProductCategory $category, ProductMeasure $measure = ProductMeasure::Volume): PurchasedItem
    {
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
            $index, $name, null, null, $unit, '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        $item->propose($proposedName, $measure, $category);
        $this->items->addAll([$item]);

        return $item;
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }
}
