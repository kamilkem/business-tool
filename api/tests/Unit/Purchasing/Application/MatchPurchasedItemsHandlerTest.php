<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsCommand;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsHandler;
use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\CatalogCategory;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\MatchCaveat;
use App\Purchasing\Domain\Matching\MatchSource;
use App\Purchasing\Domain\Matching\PriceSanity;
use App\Purchasing\Domain\Matching\ProductProposal;
use App\Purchasing\Domain\Model\AliasKeys;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Model\SellerUnit;
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
    private InMemoryProductCategoryRepository $categories;
    private ProductCategory $whisky;
    private InMemoryProductAliasRepository $aliases;
    private InMemorySellerUnitRepository $sellerUnits;
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
        $this->categories = new InMemoryProductCategoryRepository();
        $this->aliases = new InMemoryProductAliasRepository();
        $this->sellerUnits = new InMemorySellerUnitRepository();
        $this->interpreter = new InMemoryItemInterpreter();
        $tenantContext = new InMemoryTenantContext();
        $owner = UserMother::any('olivia@example.com');
        $this->organization = OrganizationMother::ownedBy($owner);
        $tenantContext->switchTo($this->organization);

        $created = new \DateTimeImmutable('2026-11-01 10:00:00');
        $alcohol = ProductCategory::create(Uuid::v7(), 'Alcohol', null, null, $owner->getId(), MembershipRole::Owner, $created);
        $this->whisky = ProductCategory::create(Uuid::v7(), 'Whisky', $alcohol, null, $owner->getId(), MembershipRole::Owner, $created);
        $this->categories->add($alcohol);
        $this->categories->add($this->whisky);
        foreach (['jack' => ['Jack Daniel\'s', ProductMeasure::Volume], 'honey' => ['Jack Daniel\'s Honey', ProductMeasure::Volume], 'rent' => ['Czynsz', ProductMeasure::None]] as $property => [$name, $measure]) {
            $this->{$property} = Product::create(Uuid::v7(), $name, null, $measure, null, false, null, $owner->getId(), MembershipRole::Owner, $created);
            $this->products->add($this->{$property});
        }

        $this->handler = new MatchPurchasedItemsHandler($this->items, $this->products, $this->categories, $this->aliases, $this->sellerUnits, $this->interpreter, $tenantContext, new MockClock(self::NOW), new NullLogger());
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

    public function test_it_does_not_assign_a_case_on_the_size_of_one_bottle(): void
    {
        // The owner confirmed the bottle row; the same seller's case row says 1 L too, but a case holds more.
        $this->learn($this->item('Jack Daniels 1L', sellerCode: 'JD-1', unit: 'szt'), $this->jack);
        $case = $this->item('Jack Daniels 1L', sellerCode: 'JD-1', unit: 'CO');

        $this->match([$case]);

        self::assertSame(PurchasedItemStatus::Suggested, $case->getStatus());
        self::assertSame($this->jack, $case->getProduct());
    }

    public function test_it_reads_the_sellers_case_with_the_multiplier_the_owner_confirmed(): void
    {
        $this->learn($this->item('Jack Daniels 1L', sellerCode: 'JD-1', unit: 'szt'), $this->jack);
        $this->sellerUnits->saveAll([new SellerUnit(Uuid::v7(), '5265877635', 'co', 6, new \DateTimeImmutable('2026-11-02 10:00:00'))]);
        $case = $this->item('Jack Daniels 1L', sellerCode: 'JD-1', unit: 'CO');
        $otherSeller = $this->item('Jack Daniels 1L', sellerCode: 'JD-1', unit: 'CO', sellerTaxId: '1111111111');

        $this->match([$case, $otherSeller]);

        self::assertSame([PurchasedItemStatus::Assigned, '6'], [$case->getStatus(), $case->getPackageSize()]);
        // Another seller's CO is another unit: the product is found by the name alias, the size is only the one read.
        self::assertSame([PurchasedItemStatus::Suggested, '1'], [$otherSeller->getStatus(), $otherSeller->getPackageSize()]);
    }

    public function test_it_suggests_what_the_model_says_for_a_case_whose_count_is_not_known(): void
    {
        $case = $this->item('Jack Daniels 1L', unit: 'CO');
        $this->interpreter->answer('Jack Daniels 1L', $this->jack->getId()->toRfc4122(), InterpretationConfidence::High, '1');

        $this->match([$case]);

        self::assertSame(PurchasedItemStatus::Suggested, $case->getStatus());
        self::assertSame('1', $case->getPackageSize());
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

    public function test_it_does_not_call_the_model_for_alias_only_matching_or_without_one(): void
    {
        $item = $this->item('J.DANIELS 1L');
        $this->interpreter->answer('J.DANIELS 1L', $this->jack->getId()->toRfc4122());

        $this->match([$item], useInterpreter: false);
        self::assertSame([], $this->interpreter->calls);
        self::assertNull($item->getMatchedAt(), 'Only the model going through an item counts as matched.');

        $this->interpreter->available = false;
        $this->match([$item]);
        self::assertSame([], $this->interpreter->calls);
    }

    public function test_it_asks_the_model_for_proposals_also_when_the_catalog_is_empty(): void
    {
        $item = $this->item('TYSKIE GOLD 0,5L');
        $this->interpreter->propose('TYSKIE GOLD 0,5L', new ProductProposal('Tyskie', ProductMeasure::Volume, null));
        $empty = new MatchPurchasedItemsHandler($this->items, new InMemoryProductRepository(), $this->categories, $this->aliases, $this->sellerUnits, $this->interpreter, $this->tenantContext(), new MockClock(self::NOW), new NullLogger());

        $empty(new MatchPurchasedItemsCommand($this->organizationId(), [$item->getId()->toRfc4122()], true));

        self::assertCount(1, $this->interpreter->calls);
        self::assertSame([], $this->interpreter->calls[0]['catalog']);
        self::assertSame('Tyskie', $item->getProposedName());
    }

    public function test_it_stores_what_the_model_proposes_for_an_item_no_product_is_and_creates_nothing(): void
    {
        $item = $this->item('TYSKIE GOLD 0,5L', sellerCode: 'T-05');
        $this->interpreter->propose('TYSKIE GOLD 0,5L', new ProductProposal('Tyskie', ProductMeasure::Volume, $this->whisky->getId()->toRfc4122()));
        $before = $this->products->search(null, null);

        $this->match([$item]);

        self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
        self::assertNull($item->getProduct());
        self::assertSame(['Tyskie', 'tyskie', ProductMeasure::Volume, $this->whisky], [$item->getProposedName(), $item->getProposedNameKey(), $item->getProposedMeasure(), $item->getProposedCategory()]);
        self::assertNotNull($item->getMatchedAt());
        self::assertSame($before, $this->products->search(null, null), 'Matching never creates a product (ADR 0044).');
        // The category tree goes to the model as ids and paths.
        self::assertEquals([new CatalogCategory($this->whisky->getParent()?->getId()->toRfc4122() ?? '', 'Alcohol'), new CatalogCategory($this->whisky->getId()->toRfc4122(), 'Alcohol > Whisky')], $this->interpreter->calls[0]['categories']);
    }

    public function test_it_drops_a_category_that_is_not_the_organizations(): void
    {
        $item = $this->item('TYSKIE GOLD 0,5L');
        $this->interpreter->propose('TYSKIE GOLD 0,5L', new ProductProposal('Tyskie', ProductMeasure::Volume, Uuid::v7()->toRfc4122()));

        $this->match([$item]);

        self::assertSame('Tyskie', $item->getProposedName());
        self::assertNull($item->getProposedCategory());
    }

    public function test_it_treats_a_proposed_name_that_a_product_has_as_a_suggested_match(): void
    {
        $item = $this->item('Whiskey JD 0,7l');
        $this->interpreter->propose('Whiskey JD 0,7l', new ProductProposal('JACK DANIEL\'S', ProductMeasure::Volume, null));

        $this->match([$item]);

        self::assertSame($this->jack, $item->getProduct());
        self::assertSame(PurchasedItemStatus::Suggested, $item->getStatus(), 'The model said "no product" and then named one: a person confirms it.');
        self::assertFalse($item->hasProposal());
    }

    public function test_it_does_not_propose_again_what_a_person_dismissed_for_the_item(): void
    {
        $item = $this->item('TYSKIE GOLD 0,5L');
        $this->interpreter->propose('TYSKIE GOLD 0,5L', new ProductProposal('Tyskie', ProductMeasure::Volume, null));
        $this->match([$item]);
        $item->dismissProposal(Uuid::v7(), MembershipRole::Owner);

        $this->match([$item]);
        self::assertFalse($item->hasProposal(), 'The same name again: dismissed.');

        $this->interpreter->propose('TYSKIE GOLD 0,5L', new ProductProposal('Tyskie Gold', ProductMeasure::Volume, null));
        $this->match([$item]);
        self::assertSame('Tyskie Gold', $item->getProposedName(), 'Another name is a new proposal.');
    }

    public function test_it_withdraws_a_proposal_when_the_item_is_matched_or_the_model_has_none(): void
    {
        $matched = $this->item('JD 0,7l');
        $gone = $this->item('TYSKIE 0,5l');
        $this->interpreter->propose('JD 0,7l', new ProductProposal('Jack', ProductMeasure::Volume, null));
        $this->interpreter->propose('TYSKIE 0,5l', new ProductProposal('Tyskie', ProductMeasure::Volume, null));
        $this->match([$matched, $gone]);
        self::assertTrue($matched->hasProposal() && $gone->hasProposal());

        $this->interpreter->answer('JD 0,7l', $this->jack->getId()->toRfc4122(), InterpretationConfidence::High);
        $this->interpreter->answer('TYSKIE 0,5l', null, InterpretationConfidence::Low);
        $this->match([$matched, $gone]);

        self::assertSame($this->jack, $matched->getProduct());
        self::assertFalse($matched->hasProposal());
        self::assertFalse($gone->hasProposal());
    }

    public function test_it_proposes_nothing_for_an_unusable_name(): void
    {
        $item = $this->item('Cos');
        $this->interpreter->propose('Cos', new ProductProposal('***', ProductMeasure::Count, null));

        $this->match([$item]);

        self::assertFalse($item->hasProposal(), 'A name with no letters or digits is no name.');
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

    public function test_it_says_what_matched_the_item_and_why_it_is_only_suggested(): void
    {
        $this->learn($this->item('JACK DANIELS 0,7L', sellerCode: 'JD-07'), $this->jack);
        $byAlias = $this->item('Whiskey JD 0,7l', sellerCode: 'JD-07');
        $noSize = $this->item('Whisky Demo');
        $this->interpreter->answer('Whisky Demo', $this->jack->getId()->toRfc4122(), InterpretationConfidence::High, null);

        $this->match([$byAlias, $noSize]);

        self::assertSame([MatchSource::SellerCode, null], [$byAlias->getMatchSource(), $byAlias->getMatchCaveat()]);
        self::assertSame([PurchasedItemStatus::Suggested, MatchSource::Model, MatchCaveat::SizeUnknown], [$noSize->getStatus(), $noSize->getMatchSource(), $noSize->getMatchCaveat()]);
    }

    public function test_it_lowers_an_assigned_item_whose_price_per_litre_is_far_from_the_confirmed_median_to_suggested(): void
    {
        $this->confirmPrices($this->jack, PriceSanity::MIN_SAMPLE);
        $this->learn($this->item('JACK DANIELS 0,7L', sellerCode: 'JD-07'), $this->jack);
        $normal = $this->item('Whiskey JD 0,7l', sellerCode: 'JD-07');
        // The same row with ten times the price: a pack of six read as one bottle's worth, or the other way round.
        $dear = $this->item('Whiskey JD 0,7l', sellerCode: 'JD-07', net: 487_800);
        $cheap = $this->item('Whiskey JD 0,7l', sellerCode: 'JD-07', net: 4_878);

        $this->match([$normal, $dear, $cheap]);

        self::assertSame([PurchasedItemStatus::Assigned, null], [$normal->getStatus(), $normal->getMatchCaveat()]);
        foreach ([$dear, $cheap] as $item) {
            self::assertSame([PurchasedItemStatus::Suggested, MatchCaveat::PriceOutlier, MatchSource::SellerCode], [$item->getStatus(), $item->getMatchCaveat(), $item->getMatchSource()]);
            self::assertSame([$this->jack, '0.7'], [$item->getProduct(), $item->getPackageSize()], 'It is still linked, with its size: only a person confirms it.');
        }
    }

    public function test_it_does_not_check_the_price_against_too_few_confirmed_items(): void
    {
        $this->confirmPrices($this->jack, PriceSanity::MIN_SAMPLE - 1);
        $this->learn($this->item('JACK DANIELS 0,7L', sellerCode: 'JD-07'), $this->jack);
        $dear = $this->item('Whiskey JD 0,7l', sellerCode: 'JD-07', net: 487_800);

        $this->match([$dear]);

        self::assertSame(PurchasedItemStatus::Assigned, $dear->getStatus());
    }

    public function test_it_samples_only_confirmed_items_of_the_product_in_the_items_currency(): void
    {
        // Five confirmed items of another product, five in euro, and five matched but not confirmed: none says anything about this price.
        $this->confirmPrices($this->honey, PriceSanity::MIN_SAMPLE);
        $this->confirmPrices($this->jack, PriceSanity::MIN_SAMPLE, currency: 'EUR');
        for ($i = 0; $i < PriceSanity::MIN_SAMPLE; ++$i) {
            $this->item('Assigned '.$i)->match($this->jack, '0.7', PurchasedItemStatus::Assigned, new \DateTimeImmutable(self::NOW));
        }
        $this->learn($this->item('JACK DANIELS 0,7L', sellerCode: 'JD-07'), $this->jack);
        $dear = $this->item('Whiskey JD 0,7l', sellerCode: 'JD-07', net: 487_800);

        $this->match([$dear]);

        self::assertSame(PurchasedItemStatus::Assigned, $dear->getStatus());
    }

    public function test_it_checks_the_price_of_what_the_model_assigns_too(): void
    {
        $this->confirmPrices($this->jack, PriceSanity::MIN_SAMPLE);
        $dear = $this->item('Whiskey Old No.7 0,7l', net: 487_800);
        $this->interpreter->answer('Whiskey Old No.7 0,7l', $this->jack->getId()->toRfc4122(), InterpretationConfidence::High, '0.7');

        $this->match([$dear]);

        self::assertSame([PurchasedItemStatus::Suggested, MatchSource::Model, MatchCaveat::PriceOutlier], [$dear->getStatus(), $dear->getMatchSource(), $dear->getMatchCaveat()]);
    }

    public function test_it_leaves_a_product_without_a_unit_and_an_item_without_a_size_unchecked(): void
    {
        $this->confirmPrices($this->rent, PriceSanity::MIN_SAMPLE);
        $this->learn($this->item('Czynsz', unit: 'mies.'), $this->rent);
        $rent = $this->item('Czynsz', unit: 'mies.', net: 9_999_999);

        $this->match([$rent]);

        self::assertSame(PurchasedItemStatus::Assigned, $rent->getStatus());
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

    /** Items a person confirmed to the product, 6 bottles of 0.7 L each at the given net amount: the sample of the price check. */
    private function confirmPrices(Product $product, int $count, int $net = 48_780, string $currency = 'PLN'): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->item('Confirmed '.$i, net: $net, currency: $currency)->link($product, '0.7', Uuid::v7(), MembershipRole::Owner);
        }
    }

    private function item(string $name, ?string $sellerCode = null, ?string $gtin = null, string $unit = 'szt.', string $sellerTaxId = '5265877635', int $net = 48_780, string $currency = 'PLN'): PurchasedItem
    {
        static $index = 0;
        ++$index;
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), $index, BusinessDate::fromString('2026-11-10'), 'regular', $currency, $sellerTaxId, 'Hurtownia Napojów',
            $index, $name, $sellerCode, $gtin, $unit, '6', '81.3', '100', null, $net, 60_000, 11_220, '23', false,
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
