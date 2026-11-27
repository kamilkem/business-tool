<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\LearnProductAliases\LearnProductAliasesCommand;
use App\Purchasing\Application\Command\LearnProductAliases\LearnProductAliasesHandler;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** What a person confirmed is remembered by the item's keys, the last confirmation of a key winning (ADR 0042 §2). */
#[CoversClass(LearnProductAliasesHandler::class)]
final class LearnProductAliasesHandlerTest extends TestCase
{
    private InMemoryPurchasedItemRepository $items;
    private InMemoryProductAliasRepository $aliases;
    private InMemorySellerUnitRepository $sellerUnits;
    private Organization $organization;
    private Product $jack;
    private Product $honey;
    private LearnProductAliasesHandler $handler;

    protected function setUp(): void
    {
        $this->items = new InMemoryPurchasedItemRepository();
        $this->aliases = new InMemoryProductAliasRepository();
        $this->sellerUnits = new InMemorySellerUnitRepository();
        $tenantContext = new InMemoryTenantContext();
        $owner = UserMother::any('olivia@example.com');
        $this->organization = OrganizationMother::ownedBy($owner);
        $tenantContext->switchTo($this->organization);
        $created = new \DateTimeImmutable('2026-11-01 10:00:00');
        $this->jack = Product::create(Uuid::v7(), 'Jack Daniel\'s', null, ProductMeasure::Volume, null, false, null, $owner->getId(), MembershipRole::Owner, $created);
        $this->honey = Product::create(Uuid::v7(), 'Jack Daniel\'s Honey', null, ProductMeasure::Volume, null, false, null, $owner->getId(), MembershipRole::Owner, $created);

        $this->handler = new LearnProductAliasesHandler($this->items, $this->aliases, $this->sellerUnits, $tenantContext, new MockClock('2026-11-26 03:00:00'));
    }

    public function test_it_remembers_every_key_of_a_confirmed_item_and_the_pack_size_of_the_keys_that_name_a_pack(): void
    {
        $item = $this->confirmed('JACK DANIELS 0,7L 40%', $this->jack, '0.7', sellerCode: 'JD-07', gtin: '5099873089798');

        $this->learn([$item]);

        /** @var array<string, ProductAlias> $byKind */
        $byKind = [];
        foreach ($this->aliases->all() as $alias) {
            $byKind[$alias->getKind()->value] = $alias;
        }
        self::assertSame(['gtin', 'seller_code', 'seller_name', 'name'], array_keys($byKind));
        self::assertSame('5099873089798', $byKind['gtin']->getKey());
        self::assertSame('5265877635|JD-07', $byKind['seller_code']->getKey());
        self::assertSame('5265877635|jack daniels 0 7l 40', $byKind['seller_name']->getKey());
        self::assertSame('jack daniels abv40', $byKind['name']->getKey());
        foreach ([AliasKind::Gtin, AliasKind::SellerCode, AliasKind::SellerName] as $kind) {
            self::assertSame('0.7', $byKind[$kind->value]->getPackageSize());
        }
        self::assertNull($byKind['name']->getPackageSize(), 'The name stands for the product across sizes.');
        foreach ($byKind as $alias) {
            self::assertSame($this->jack, $alias->getProduct());
        }
    }

    public function test_it_lets_the_last_confirmation_of_a_key_win(): void
    {
        $first = $this->confirmed('JACK DANIELS 0,7L', $this->jack, '0.7', sellerCode: 'JD-07');
        $this->learn([$first]);
        $second = $this->confirmed('JACK DANIELS 0,7L', $this->honey, '0.7', sellerCode: 'JD-07');

        $this->learn([$second]);

        foreach ($this->aliases->all() as $alias) {
            self::assertSame($this->honey, $alias->getProduct());
        }
        self::assertCount(3, $this->aliases->all(), 'Repointed, not added again.');
    }

    public function test_it_lets_the_later_item_of_one_batch_win_a_shared_key(): void
    {
        $a = $this->confirmed('JACK DANIELS 0,7L', $this->jack, '0.7');
        $b = $this->confirmed('JACK DANIELS 1L', $this->honey, '1');

        $this->learn([$a, $b]);

        $byKey = [];
        foreach ($this->aliases->all() as $alias) {
            $byKey[$alias->getKind()->value.'#'.$alias->getKey()] = $alias->getProduct();
        }
        self::assertSame($this->honey, $byKey['name#jack daniels']);
        self::assertSame($this->jack, $byKey['seller_name#5265877635|jack daniels 0 7l']);
        self::assertSame($this->honey, $byKey['seller_name#5265877635|jack daniels 1l']);
    }

    public function test_it_learns_nothing_from_an_item_that_is_not_confirmed(): void
    {
        $unconfirmed = $this->item('JACK DANIELS 0,7L');

        $this->learn([$unconfirmed]);

        self::assertSame([], $this->aliases->all());
        self::assertSame(0, $this->aliases->saveAllCallCount);
    }

    public function test_it_identifies_a_seller_without_a_tax_id_by_its_name(): void
    {
        $item = $this->confirmed('Woda 1l', $this->jack, '1', sellerTaxId: null, sellerCode: 'W1');

        $this->learn([$item]);

        $keys = array_map(static fn ($alias): string => $alias->getKey(), $this->aliases->all());
        self::assertContains('name:hurtownia napojów|W1', $keys);
    }

    public function test_it_learns_how_many_pieces_a_sellers_unit_holds_from_a_confirmation(): void
    {
        $item = $this->confirmed('Jack Daniels 1L', $this->jack, '6', unit: 'CO');

        $this->learn([$item]);

        [$unit] = $this->sellerUnits->all();
        self::assertSame(['5265877635', 'co', 6], [$unit->getSellerKey(), $unit->getUnitKey(), $unit->getMultiplier()]);
    }

    public function test_it_lets_the_last_confirmation_of_a_sellers_unit_win_and_keeps_sellers_apart(): void
    {
        $this->learn([$this->confirmed('Jack Daniels 1L', $this->jack, '6', unit: 'CO')]);
        $this->learn([$this->confirmed('Cola 1L', $this->honey, '12', unit: 'CO')]);
        $this->learn([$this->confirmed('Piwo 0,5L', $this->jack, '12', unit: 'CO', sellerTaxId: '1111111111')]);

        $byKey = [];
        foreach ($this->sellerUnits->all() as $unit) {
            $byKey[$unit->getSellerKey().'#'.$unit->getUnitKey()] = $unit->getMultiplier();
        }
        self::assertSame(['5265877635#co' => 12, '1111111111#co' => 24], $byKey);
    }

    public function test_it_learns_no_multiplier_for_a_unit_whose_count_is_known_or_a_size_that_is_not_a_multiple(): void
    {
        $this->learn([
            $this->confirmed('Jack Daniels 1L', $this->jack, '6', unit: 'szt.'),
            $this->confirmed('Cola 1L', $this->jack, '2.5', unit: 'CO'),
            $this->confirmed('Piwo', $this->jack, '6', unit: 'CO'),
        ]);

        self::assertSame([], $this->sellerUnits->all());
    }

    public function test_it_refuses_another_organization(): void
    {
        $this->expectException(\LogicException::class);
        ($this->handler)(new LearnProductAliasesCommand(Uuid::v7()->toRfc4122(), []));
    }

    /** @param list<PurchasedItem> $items */
    private function learn(array $items): void
    {
        ($this->handler)(new LearnProductAliasesCommand(
            $this->organization->getId()->toRfc4122(),
            array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $items),
        ));
    }

    private function confirmed(string $name, Product $product, string $size, ?string $sellerCode = null, ?string $gtin = null, ?string $sellerTaxId = '5265877635', string $unit = 'szt.'): PurchasedItem
    {
        $item = $this->item($name, $sellerCode, $gtin, $sellerTaxId, $unit);
        $item->link($product, $size, Uuid::v7(), MembershipRole::Owner);

        return $item;
    }

    private function item(string $name, ?string $sellerCode = null, ?string $gtin = null, ?string $sellerTaxId = '5265877635', string $unit = 'szt.'): PurchasedItem
    {
        static $index = 0;
        ++$index;
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', $sellerTaxId, 'Hurtownia Napojów',
            $index, $name, $sellerCode, $gtin, $unit, '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        $this->items->addAll([$item]);

        return $item;
    }
}
