<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\ApplyPackageSize\ApplyPackageSizeCommand;
use App\Purchasing\Application\Command\ApplyPackageSize\ApplyPackageSizeHandler;
use App\Purchasing\Application\Query\GetProductPackageSizes\GetProductPackageSizesHandler;
use App\Purchasing\Application\Query\GetProductPackageSizes\GetProductPackageSizesQuery;
use App\Purchasing\Application\Query\GetProductPackageSizes\ProductPackageSizesView;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\PackageSize\PackageSizeCount;
use App\Purchasing\Domain\PackageSize\PackageSizeGroup;
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
 * The package size asked about for the product's other items (ADR 0049): Jack Daniel's with a 0.7 L bottle
 * on `szt.`, cases on `CO` and `kart.` that have no size, a case with another size, and an item with no product.
 */
#[CoversClass(ApplyPackageSizeHandler::class)]
#[CoversClass(GetProductPackageSizesHandler::class)]
#[CoversClass(PackageSizeGroup::class)]
final class PackageSizeHandlersTest extends TestCase
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
    private PurchasedItem $bottle;
    private PurchasedItem $confirmedCase;
    private PurchasedItem $assignedCase;
    private PurchasedItem $carton;
    private PurchasedItem $sizedCase;
    private PurchasedItem $colaCase;
    private PurchasedItem $loose;
    private PurchasedItem $bundleCase;

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

        $invoiceId = Uuid::v7();
        $this->items->addAll([
            $this->bottle = $this->record($invoiceId, 1, 'szt.', $this->jack, '0.7', PurchasedItemStatus::Confirmed),
            $this->confirmedCase = $this->record($invoiceId, 2, 'CO', $this->jack, null, PurchasedItemStatus::Confirmed),
            $this->assignedCase = $this->record($invoiceId, 3, 'co.', $this->jack, null, PurchasedItemStatus::Assigned),
            $this->carton = $this->record($invoiceId, 4, 'kart.', $this->jack, null, PurchasedItemStatus::Suggested),
            $this->sizedCase = $this->record($invoiceId, 5, 'CO', $this->jack, '4.2', PurchasedItemStatus::Confirmed),
            $this->bundleCase = $this->record($invoiceId, 8, 'CO', $this->jack, '0.7', PurchasedItemStatus::Confirmed, 6),
            $this->colaCase = $this->record($invoiceId, 6, 'CO', $this->cola, null, PurchasedItemStatus::Confirmed),
            $this->loose = $this->record($invoiceId, 7, 'CO', null, null, PurchasedItemStatus::Unassigned),
        ]);

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

    // ---- The groups the dialog asks about ----

    public function test_it_groups_the_items_of_the_product_by_unit_and_package_size(): void
    {
        $view = $this->sizeGroups($this->owner, $this->jack->getId()->toRfc4122());

        self::assertInstanceOf(ProductPackageSizesView::class, $view);
        // `CO` and `co.` are one unit; the items with no size come first, and what the seller printed most often is shown.
        self::assertSame([
            ['co', 'CO', null, null, 2, 1],
            ['co', 'CO', '0.7', 6, 1, 1],
            ['co', 'CO', '4.2', null, 1, 1],
            ['kart', 'kart.', null, null, 1, 0],
            ['szt', 'szt.', '0.7', null, 1, 1],
        ], array_map(static fn (PackageSizeGroup $group): array => [$group->unitKey, $group->unit, $group->packageSize, $group->bundleCount, $group->count, $group->confirmedCount], $view->groups));
    }

    public function test_it_leaves_another_products_items_and_items_with_no_product_out_of_the_groups(): void
    {
        $view = $this->sizeGroups($this->owner, $this->cola->getId()->toRfc4122());

        self::assertInstanceOf(ProductPackageSizesView::class, $view);
        self::assertCount(1, $view->groups);
        self::assertSame(1, $view->groups[0]->count);
    }

    public function test_it_puts_the_items_with_no_unit_last_and_merges_the_counts_of_one_group(): void
    {
        $groups = PackageSizeGroup::of([
            new PackageSizeCount(null, '1.000000', null, false, 1),
            new PackageSizeCount('szt', '0.700000', null, true, 2),
            new PackageSizeCount('szt.', '0.700000', null, false, 3),
            new PackageSizeCount('SZT.', '0.7', null, false, 1),
            new PackageSizeCount('', null, null, false, 1),
            new PackageSizeCount('szt', '0.7', 6, false, 2),
        ]);

        self::assertSame(
            [['szt', 'szt.', '0.7', null, 6, 2], ['szt', 'szt', '0.7', 6, 2, 0], [null, null, null, null, 1, 0], [null, null, '1', null, 1, 0]],
            array_map(static fn (PackageSizeGroup $group): array => [$group->unitKey, $group->unit, $group->packageSize, $group->bundleCount, $group->count, $group->confirmedCount], $groups),
        );
    }

    public function test_it_finds_no_groups_for_a_product_that_is_not_there_or_for_a_stranger_and_refuses_an_employee(): void
    {
        self::assertNull($this->sizeGroups($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->sizeGroups($this->owner, 'not-a-uuid'));
        self::assertNull($this->sizeGroups(UserMother::any('stranger@example.com'), $this->jack->getId()->toRfc4122()));

        $this->expectException(PurchasingManagementForbiddenException::class);
        $this->sizeGroups($this->employee, $this->jack->getId()->toRfc4122());
    }

    // ---- Applying the size ----

    #[DataProvider('managers')]
    public function test_it_sets_the_size_on_the_chosen_groups_only(string $actor): void
    {
        $this->apply('owner' === $actor ? $this->owner : $this->manager, '4.20', [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null]]);

        self::assertSame('4.2', $this->confirmedCase->getPackageSize());
        self::assertSame('4.2', $this->assignedCase->getPackageSize());
        // Not chosen: another unit with no size, the bottle, the case that has another size, another product's, an item with no product.
        self::assertNull($this->carton->getPackageSize());
        self::assertSame('0.7', $this->bottle->getPackageSize());
        self::assertSame('4.2', $this->sizedCase->getPackageSize());
        self::assertNull($this->colaCase->getPackageSize());
        self::assertNull($this->loose->getPackageSize());
        self::assertSame(1, $this->items->saveCallCount);
    }

    public function test_it_writes_only_the_size_and_never_the_link_or_the_status(): void
    {
        $this->apply($this->owner, '6', [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null], ['unitKey' => 'kart', 'packageSize' => null, 'bundleCount' => null]]);

        foreach ([[$this->confirmedCase, PurchasedItemStatus::Confirmed], [$this->assignedCase, PurchasedItemStatus::Assigned], [$this->carton, PurchasedItemStatus::Suggested]] as [$item, $status]) {
            self::assertSame($status, $item->getStatus());
            self::assertSame($this->jack, $item->getProduct());
            self::assertSame('6', $item->getPackageSize());
        }
    }

    public function test_it_does_not_touch_an_item_a_person_confirmed_with_another_size_unless_that_group_is_chosen(): void
    {
        $this->apply($this->owner, '6', [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null]]);
        self::assertSame('4.2', $this->sizedCase->getPackageSize());

        $this->apply($this->owner, '6', [['unitKey' => 'co', 'packageSize' => '4.20', 'bundleCount' => null]]);
        self::assertSame('6', $this->sizedCase->getPackageSize());
        self::assertSame(PurchasedItemStatus::Confirmed, $this->sizedCase->getStatus());
        self::assertSame('0.7', $this->bottle->getPackageSize());
    }

    public function test_it_sets_a_bundle_and_tells_a_bundle_from_a_plain_package_of_the_same_size(): void
    {
        $this->apply($this->owner, '0.7', [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null]], null, 6);

        self::assertSame(['0.7', 6, '4.2'], [$this->confirmedCase->getPackageSize(), $this->confirmedCase->getBundleCount(), $this->confirmedCase->getPackageTotal()]);
        // The case with a size of 4.2 and the one that already is a bundle of 6 × 0.7 are other groups.
        self::assertSame([null, '4.2'], [$this->sizedCase->getBundleCount(), $this->sizedCase->getPackageSize()]);

        $this->apply($this->owner, '1', [['unitKey' => 'co', 'packageSize' => '0.7', 'bundleCount' => 6]]);
        // Both cases now are a bundle of 6 × 0.7, so both are in that group.
        self::assertSame([['1', null], ['1', null]], [[$this->bundleCase->getPackageSize(), $this->bundleCase->getBundleCount()], [$this->confirmedCase->getPackageSize(), $this->confirmedCase->getBundleCount()]]);
    }

    public function test_it_refuses_a_bundle_count_out_of_range_and_changes_nothing(): void
    {
        $this->expectException(\App\Purchasing\Domain\Exception\InvalidBundleCountException::class);

        $this->apply($this->owner, '0.7', [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null]], null, 1);
    }

    public function test_it_sets_the_size_on_items_with_no_unit(): void
    {
        $invoiceId = Uuid::v7();
        $noUnit = $this->record($invoiceId, 1, null, $this->jack, null, PurchasedItemStatus::Confirmed);
        $this->items->addAll([$noUnit]);

        $this->apply($this->owner, '1', [['unitKey' => null, 'packageSize' => null, 'bundleCount' => null]]);

        self::assertSame('1', $noUnit->getPackageSize());
        self::assertNull($this->carton->getPackageSize());
    }

    public function test_it_publishes_only_the_confirmed_items_it_changed_so_that_their_aliases_learn_the_size(): void
    {
        $this->apply($this->owner, '6', [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null], ['unitKey' => 'kart', 'packageSize' => null, 'bundleCount' => null]]);

        self::assertCount(1, $this->events->events);
        $event = $this->events->events[0];
        self::assertInstanceOf(PurchasedItemsConfirmed::class, $event);
        self::assertSame([$this->confirmedCase->getId()->toRfc4122()], $event->itemIds);
        self::assertSame($this->organization->getId()->toRfc4122(), $event->organizationId);
    }

    public function test_it_changes_nothing_and_publishes_nothing_when_no_item_is_in_the_chosen_groups(): void
    {
        $this->apply($this->owner, '6', [['unitKey' => 'fl', 'packageSize' => null, 'bundleCount' => null]]);
        $this->apply($this->owner, '6', []);

        self::assertSame(0, $this->items->saveCallCount);
        self::assertSame([], $this->events->events);
    }

    public function test_it_refuses_an_employee_a_stranger_another_organizations_product_and_a_bad_size_and_changes_nothing(): void
    {
        $targets = [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null]];
        foreach ([
            [PurchasingManagementForbiddenException::class, $this->employee, $this->jack->getId()->toRfc4122(), '6'],
            [OrganizationNotFoundException::class, UserMother::any('stranger@example.com'), $this->jack->getId()->toRfc4122(), '6'],
            [ProductNotFoundException::class, $this->owner, Uuid::v7()->toRfc4122(), '6'],
            [ProductNotFoundException::class, $this->owner, 'not-a-uuid', '6'],
            [InvalidPackageSizeException::class, $this->owner, $this->jack->getId()->toRfc4122(), '0'],
            [InvalidPackageSizeException::class, $this->owner, $this->jack->getId()->toRfc4122(), '0.1234567'],
        ] as [$exception, $actor, $productId, $size]) {
            try {
                $this->apply($actor, $size, $targets, $productId);
                self::fail(\sprintf('%s was expected.', $exception));
            } catch (\Throwable $e) {
                self::assertInstanceOf($exception, $e);
            }
        }

        self::assertNull($this->confirmedCase->getPackageSize());
        self::assertSame(0, $this->items->saveCallCount);
        self::assertSame([], $this->events->events);
    }

    public function test_it_refuses_a_group_with_a_size_that_is_not_a_decimal_rather_than_reading_it_as_no_size(): void
    {
        $this->expectException(InvalidPackageSizeException::class);

        $this->apply($this->owner, '6', [['unitKey' => 'co', 'packageSize' => 'x', 'bundleCount' => null]]);
    }

    public function test_it_refuses_too_many_groups(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->apply($this->owner, '6', array_fill(0, ApplyPackageSizeCommand::MAX_TARGETS + 1, ['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null]));
    }

    // ---- Helpers ----

    private function record(Uuid $invoiceId, int $index, ?string $unit, ?Product $product, ?string $packageSize, PurchasedItemStatus $status, ?int $bundleCount = null): PurchasedItem
    {
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            $invoiceId, $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
            $index, 'JACK DANIELS 0,7L', null, null, $unit, '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        if (null !== $product) {
            $item->link($product, $packageSize, $this->owner->getId(), MembershipRole::Owner, $bundleCount);
            (new \ReflectionProperty(PurchasedItem::class, 'status'))->setValue($item, $status);
        }

        return $item;
    }

    private function sizeGroups(User $actor, string $productId): ?ProductPackageSizesView
    {
        return (new GetProductPackageSizesHandler($this->products, $this->items, $this->queryBus))(
            new GetProductPackageSizesQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $productId),
        );
    }

    /** @param list<array{unitKey: ?string, packageSize: ?string, bundleCount: ?int}> $targets */
    private function apply(User $actor, string $packageSize, array $targets, ?string $productId = null, ?int $bundleCount = null): void
    {
        (new ApplyPackageSizeHandler($this->items, $this->products, $this->queryBus, $this->events))(
            new ApplyPackageSizeCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $productId ?? $this->jack->getId()->toRfc4122(), $packageSize, $bundleCount, $targets),
        );
    }
}
