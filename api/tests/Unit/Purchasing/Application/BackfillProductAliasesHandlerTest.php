<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\BackfillProductAliases\BackfillProductAliasesCommand;
use App\Purchasing\Application\Command\BackfillProductAliases\BackfillProductAliasesHandler;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** Aliases from items confirmed before aliases were learned: only keys that have none, and no item changes (ADR 0045 §3). */
#[CoversClass(BackfillProductAliasesHandler::class)]
#[CoversClass(BackfillProductAliasesCommand::class)]
final class BackfillProductAliasesHandlerTest extends TestCase
{
    private InMemoryPurchasedItemRepository $items;
    private InMemoryProductAliasRepository $aliases;
    private Organization $organization;
    private Product $jack;
    private Product $honey;
    private BackfillProductAliasesHandler $handler;

    protected function setUp(): void
    {
        $this->items = new InMemoryPurchasedItemRepository();
        $this->aliases = new InMemoryProductAliasRepository();
        $tenantContext = new InMemoryTenantContext();
        $owner = UserMother::any('olivia@example.com');
        $this->organization = OrganizationMother::ownedBy($owner);
        $tenantContext->switchTo($this->organization);
        $created = new \DateTimeImmutable('2026-11-01 10:00:00');
        $this->jack = Product::create(Uuid::v7(), 'Jack Daniel\'s', null, ProductMeasure::Volume, null, false, null, $owner->getId(), MembershipRole::Owner, $created);
        $this->honey = Product::create(Uuid::v7(), 'Jack Daniel\'s Honey', null, ProductMeasure::Volume, null, false, null, $owner->getId(), MembershipRole::Owner, $created);

        $this->handler = new BackfillProductAliasesHandler($this->items, $this->aliases, $tenantContext, new MockClock('2026-11-26 03:00:00'), new RecordingLogger());
    }

    public function test_it_creates_the_aliases_a_confirmation_would_have_taught(): void
    {
        $item = $this->confirmed('JACK DANIELS 0,7L 40%', $this->jack, '0.7', sellerCode: 'JD-07', gtin: '5099873089798');

        $this->backfill([$item]);

        $byKind = $this->byKind();
        self::assertSame(['gtin', 'seller_code', 'seller_name', 'name'], array_keys($byKind));
        self::assertSame($this->jack, $byKind['gtin']->getProduct());
        self::assertSame('0.7', $byKind['seller_code']->getPackageSize());
        self::assertNull($byKind['name']->getPackageSize());
    }

    public function test_it_changes_no_item_and_no_link(): void
    {
        $confirmed = $this->confirmed('JACK DANIELS 0,7L', $this->jack, '0.7');
        $open = $this->item('JACK DANIELS 0,7L');

        $this->backfill([$confirmed, $open]);

        self::assertSame([PurchasedItemStatus::Confirmed, $this->jack, '0.7'], [$confirmed->getStatus(), $confirmed->getProduct(), $confirmed->getPackageSize()]);
        self::assertSame([PurchasedItemStatus::Unassigned, null], [$open->getStatus(), $open->getProduct()]);
        self::assertNull($open->getMatchedAt());
        self::assertSame(0, $this->items->saveCallCount, 'Items are only read.');
    }

    public function test_it_keeps_an_alias_that_already_exists(): void
    {
        $this->aliases->saveAll([new ProductAlias(Uuid::v7(), AliasKind::Name, 'jack daniels', $this->honey, null, new \DateTimeImmutable('2026-11-20 10:00:00'))]);
        $old = $this->confirmed('JACK DANIELS 0,7L', $this->jack, '0.7');

        $this->backfill([$old]);

        $byKind = $this->byKind();
        self::assertSame($this->honey, $byKind['name']->getProduct(), 'A later confirmation taught it: it is the better word.');
        self::assertSame($this->jack, $byKind['seller_name']->getProduct(), 'A key with no alias gets one.');
    }

    public function test_it_lets_the_first_item_of_a_key_decide_so_the_caller_sends_the_newest_first(): void
    {
        $newest = $this->confirmed('JACK DANIELS 0,7L', $this->honey, '0.7');
        $older = $this->confirmed('JACK DANIELS 0,7L', $this->jack, '0.7');

        $this->backfill([$newest, $older]);

        self::assertCount(2, $this->aliases->all(), 'seller_name and name, once each.');
        foreach ($this->aliases->all() as $alias) {
            self::assertSame($this->honey, $alias->getProduct());
        }
    }

    public function test_it_is_idempotent(): void
    {
        $item = $this->confirmed('JACK DANIELS 0,7L', $this->jack, '0.7', sellerCode: 'JD-07');
        $this->backfill([$item]);
        $before = $this->aliases->all();

        $this->backfill([$item]);

        self::assertSame($before, $this->aliases->all());
    }

    public function test_it_skips_items_that_are_not_confirmed_with_a_product(): void
    {
        $unassigned = $this->item('Piwo jasne');
        $removed = $this->confirmed('Wino', $this->jack, '1');
        $removed->link(null, null, Uuid::v7(), MembershipRole::Owner);

        $this->backfill([$unassigned, $removed, null]);

        self::assertSame([], $this->aliases->all());
    }

    public function test_it_refuses_another_organization(): void
    {
        $this->expectException(\LogicException::class);

        ($this->handler)(new BackfillProductAliasesCommand(Uuid::v7()->toRfc4122(), []));
    }

    public function test_it_refuses_more_items_than_the_limit(): void
    {
        $this->expectException(\LogicException::class);

        ($this->handler)(new BackfillProductAliasesCommand($this->organization->getId()->toRfc4122(), array_fill(0, BackfillProductAliasesCommand::MAX_ITEMS + 1, Uuid::v7()->toRfc4122())));
    }

    /** @return array<string, ProductAlias> the aliases by kind value; there is one of each kind at most in these tests */
    private function byKind(): array
    {
        $byKind = [];
        foreach ($this->aliases->all() as $alias) {
            $byKind[$alias->getKind()->value] = $alias;
        }

        return $byKind;
    }

    /** @param list<PurchasedItem|null> $items null: an id that is no item */
    private function backfill(array $items): void
    {
        ($this->handler)(new BackfillProductAliasesCommand(
            $this->organization->getId()->toRfc4122(),
            array_map(static fn (?PurchasedItem $item): string => $item?->getId()->toRfc4122() ?? Uuid::v7()->toRfc4122(), $items),
        ));
    }

    private function confirmed(string $name, Product $product, string $size, ?string $sellerCode = null, ?string $gtin = null): PurchasedItem
    {
        $item = $this->item($name, $sellerCode, $gtin);
        $item->link($product, $size, Uuid::v7(), MembershipRole::Owner);

        return $item;
    }

    private function item(string $name, ?string $sellerCode = null, ?string $gtin = null): PurchasedItem
    {
        static $index = 0;
        ++$index;
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
            $index, $name, $sellerCode, $gtin, 'szt.', '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        $this->items->addAll([$item]);

        return $item;
    }
}
