<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsCommand;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsHandler;
use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Model\AliasKeys;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\InMemoryItemInterpreter;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** Aliases decide first, the model decides what no alias knows, and a person's decision is never touched (ADR 0042 §3). */
#[CoversClass(MatchPurchasedItemsHandler::class)]
#[CoversClass(MatchPurchasedItemsCommand::class)]
final class MatchPurchasedItemsHandlerTest extends TestCase
{
    private const string NOW = '2026-11-26 03:00:00';

    private InMemoryPurchasedItemRepository $items;
    private InMemoryProductRepository $products;
    private InMemoryProductAliasRepository $aliases;
    private InMemoryItemInterpreter $interpreter;
    private Organization $organization;
    private Product $jack;
    private Product $honey;
    private Product $rent;
    private MatchPurchasedItemsHandler $handler;

    protected function setUp(): void
    {
        $this->items = new InMemoryPurchasedItemRepository();
        $this->products = new InMemoryProductRepository();
        $this->aliases = new InMemoryProductAliasRepository();
        $this->interpreter = new InMemoryItemInterpreter();
        $tenantContext = new InMemoryTenantContext();
        $owner = UserMother::any('olivia@example.com');
        $this->organization = OrganizationMother::ownedBy($owner);
        $tenantContext->switchTo($this->organization);

        $created = new \DateTimeImmutable('2026-11-01 10:00:00');
        foreach (['jack' => ['Jack Daniel\'s', ProductMeasure::Volume], 'honey' => ['Jack Daniel\'s Honey', ProductMeasure::Volume], 'rent' => ['Czynsz', ProductMeasure::None]] as $property => [$name, $measure]) {
            $this->{$property} = Product::create(Uuid::v7(), $name, null, $measure, null, false, null, $owner->getId(), MembershipRole::Owner, $created);
            $this->products->add($this->{$property});
        }

        $this->handler = new MatchPurchasedItemsHandler($this->items, $this->products, $this->aliases, $this->interpreter, $tenantContext, new MockClock(self::NOW), new NullLogger());
    }

    public function test_it_assigns_by_the_strongest_alias_and_takes_the_package_size_from_the_name(): void
    {
        $known = $this->item('JACK DANIELS 0,7L 40%', sellerCode: 'JD-07', gtin: '5099873089798');
        $this->learn($known, $this->jack);
        $sibling = $this->item('Whiskey JD 0,7l', sellerCode: 'JD-07', gtin: '5099873089798');

        $this->match([$sibling]);

        self::assertSame(PurchasedItemStatus::Assigned, $sibling->getStatus());
        self::assertSame($this->jack, $sibling->getProduct());
        self::assertSame('0.7', $sibling->getPackageSize());
        self::assertSame([], $this->interpreter->calls, 'An alias is the owner\'s word: the model is not asked.');
    }

    public function test_it_suggests_when_the_aliases_of_the_item_disagree(): void
    {
        $this->learn($this->item('JACK DANIELS 0,7L', gtin: '5099873089798'), $this->jack);
        $this->learn($this->item('Another name 1L', sellerCode: 'JD-07'), $this->honey);
        $item = $this->item('Some spelling 0,7l', sellerCode: 'JD-07', gtin: '5099873089798');

        $this->match([$item]);

        self::assertSame(PurchasedItemStatus::Suggested, $item->getStatus());
        self::assertSame($this->jack, $item->getProduct(), 'The strongest kind (GTIN) is the suggestion.');
    }

    public function test_it_never_reads_or_changes_a_confirmed_item_or_one_a_person_took_off_its_product(): void
    {
        $confirmed = $this->item('JACK DANIELS 0,7L');
        $confirmed->link($this->honey, '0.7', Uuid::v7(), MembershipRole::Owner);
        $removed = $this->item('JACK DANIELS 1L');
        $removed->link($this->jack, '1', Uuid::v7(), MembershipRole::Owner);
        $removed->link(null, '1', Uuid::v7(), MembershipRole::Owner);
        $this->interpreter->answer('JACK DANIELS 0,7L', $this->jack->getId()->toRfc4122());
        $this->interpreter->answer('JACK DANIELS 1L', $this->jack->getId()->toRfc4122());

        $this->match([$confirmed, $removed]);

        self::assertSame(PurchasedItemStatus::Confirmed, $confirmed->getStatus());
        self::assertSame($this->honey, $confirmed->getProduct());
        self::assertSame(PurchasedItemStatus::Unassigned, $removed->getStatus());
        self::assertNull($removed->getProduct());
        self::assertSame([], $this->interpreter->calls);
    }

    public function test_it_asks_the_model_about_what_no_alias_knows_once_per_distinct_row(): void
    {
        $a = $this->item('J.DANIELS 1L', sellerCode: 'X1');
        $b = $this->item('J.DANIELS 1L', sellerCode: 'X1');
        $c = $this->item('Wynajem lokalu', unit: 'mies.');
        $this->interpreter->answer('J.DANIELS 1L', $this->jack->getId()->toRfc4122());
        $this->interpreter->answer('Wynajem lokalu', $this->rent->getId()->toRfc4122());

        $this->match([$a, $b, $c]);

        self::assertCount(1, $this->interpreter->calls);
        self::assertCount(2, $this->interpreter->calls[0]['items'], 'Two distinct rows, not three items.');
        self::assertCount(3, $this->interpreter->calls[0]['catalog']);
        foreach ([$a, $b] as $item) {
            self::assertSame(PurchasedItemStatus::Assigned, $item->getStatus());
            self::assertSame($this->jack, $item->getProduct());
            self::assertSame('1', $item->getPackageSize());
        }
        self::assertSame(PurchasedItemStatus::Assigned, $c->getStatus());
        self::assertSame($this->rent, $c->getProduct());
        self::assertNull($c->getPackageSize());
        self::assertSame(1, $this->items->saveCallCount);
    }

    public function test_it_sends_the_model_names_units_and_seller_codes_and_nothing_else_of_the_item(): void
    {
        $this->match([$this->item('J.DANIELS 1L', sellerCode: 'X1')]);

        $question = $this->interpreter->calls[0]['items'][0];
        self::assertSame(['name' => 'J.DANIELS 1L', 'unit' => 'szt.', 'sellerCode' => 'X1'], ['name' => $question->name, 'unit' => $question->unit, 'sellerCode' => $question->sellerCode]);
        self::assertSame(['key', 'name', 'unit', 'sellerCode'], array_keys(get_object_vars($question)));
    }

    public function test_it_suggests_what_the_model_is_not_sure_of_and_leaves_a_guess_unassigned(): void
    {
        $medium = $this->item('Whisky z Tennessee 0,7l');
        $low = $this->item('Cos z beczki');
        $this->interpreter->answer('Whisky z Tennessee 0,7l', $this->jack->getId()->toRfc4122(), InterpretationConfidence::Medium);
        $this->interpreter->answer('Cos z beczki', $this->jack->getId()->toRfc4122(), InterpretationConfidence::Low);

        $this->match([$medium, $low]);

        self::assertSame(PurchasedItemStatus::Suggested, $medium->getStatus());
        self::assertSame($this->jack, $medium->getProduct());
        self::assertSame(PurchasedItemStatus::Unassigned, $low->getStatus());
        self::assertNull($low->getProduct());
        self::assertNotNull($low->getMatchedAt(), 'It was asked about: not again.');
    }

    public function test_it_ignores_a_product_the_organization_does_not_have(): void
    {
        $item = $this->item('J.DANIELS 1L');
        $this->interpreter->answer('J.DANIELS 1L', Uuid::v7()->toRfc4122());

        $this->match([$item]);

        self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
        self::assertNull($item->getProduct());
    }

    public function test_it_does_not_call_the_model_for_alias_only_matching_without_one_or_without_a_catalog(): void
    {
        $item = $this->item('J.DANIELS 1L');
        $this->interpreter->answer('J.DANIELS 1L', $this->jack->getId()->toRfc4122());

        $this->match([$item], useInterpreter: false);
        self::assertSame([], $this->interpreter->calls);
        self::assertNull($item->getMatchedAt(), 'Only the model going through an item counts as matched.');

        $this->interpreter->available = false;
        $this->match([$item]);
        self::assertSame([], $this->interpreter->calls);

        $this->interpreter->available = true;
        $empty = new MatchPurchasedItemsHandler($this->items, new InMemoryProductRepository(), $this->aliases, $this->interpreter, $this->tenantContext(), new MockClock(self::NOW), new NullLogger());
        $empty(new MatchPurchasedItemsCommand($this->organizationId(), [$item->getId()->toRfc4122()], true));
        self::assertSame([], $this->interpreter->calls);
    }

    public function test_it_saves_what_the_aliases_decided_and_rethrows_when_the_model_is_unavailable(): void
    {
        $known = $this->item('JACK DANIELS 0,7L', sellerCode: 'JD-07');
        $this->learn($known, $this->jack);
        $byAlias = $this->item('JD 0,7l', sellerCode: 'JD-07');
        $open = $this->item('Cos nowego 1l');
        $this->interpreter->failure = ItemInterpreterUnavailableException::because('the API answered 429 (rate_limit_error)');

        try {
            $this->match([$byAlias, $open]);
            self::fail('The job has to hear about it.');
        } catch (ItemInterpreterUnavailableException) {
        }

        self::assertSame(PurchasedItemStatus::Assigned, $byAlias->getStatus());
        self::assertSame(1, $this->items->saveCallCount);
        self::assertNull($open->getMatchedAt(), 'Not asked about: a later run tries again.');
    }

    public function test_it_puts_the_model_questions_in_batches(): void
    {
        $items = [];
        for ($i = 1; $i <= MatchPurchasedItemsHandler::BATCH_SIZE + 1; ++$i) {
            $items[] = $this->item('Produkt '.$i);
        }

        $this->match($items);

        self::assertCount(2, $this->interpreter->calls);
        self::assertCount(MatchPurchasedItemsHandler::BATCH_SIZE, $this->interpreter->calls[0]['items']);
        self::assertCount(1, $this->interpreter->calls[1]['items']);
    }

    public function test_it_refuses_another_organization(): void
    {
        $this->expectException(\LogicException::class);
        ($this->handler)(new MatchPurchasedItemsCommand(Uuid::v7()->toRfc4122(), [], true));
    }

    public function test_it_refuses_more_items_than_the_limit(): void
    {
        $this->expectException(\LogicException::class);
        ($this->handler)(new MatchPurchasedItemsCommand($this->organizationId(), array_fill(0, MatchPurchasedItemsCommand::MAX_ITEMS + 1, Uuid::v7()->toRfc4122()), true));
    }

    /** @param list<PurchasedItem> $items */
    private function match(array $items, bool $useInterpreter = true): void
    {
        ($this->handler)(new MatchPurchasedItemsCommand(
            $this->organizationId(),
            array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $items),
            $useInterpreter,
        ));
    }

    /** What a person's confirmation leaves behind: an alias for each key of the item. */
    private function learn(PurchasedItem $item, Product $product): void
    {
        $aliases = [];
        foreach (AliasKeys::of($item) as $kind => $key) {
            $aliases[] = new ProductAlias(Uuid::v7(), AliasKind::from($kind), $key, $product, '0.7', new \DateTimeImmutable('2026-11-02 10:00:00'));
        }
        $this->aliases->saveAll($aliases);
    }

    private function item(string $name, ?string $sellerCode = null, ?string $gtin = null, string $unit = 'szt.'): PurchasedItem
    {
        static $index = 0;
        ++$index;
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
            $index, $name, $sellerCode, $gtin, $unit, '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        $this->items->addAll([$item]);

        return $item;
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    private function tenantContext(): InMemoryTenantContext
    {
        $context = new InMemoryTenantContext();
        $context->switchTo($this->organization);

        return $context;
    }
}
