<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\ItemInterpretation;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Matching\MatchDecision;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** When matching is confident, and when it only suggests (ADR 0042 §3, §5). */
#[CoversClass(MatchDecision::class)]
final class MatchDecisionTest extends TestCase
{
    public function test_it_assigns_by_an_alias_when_the_item_names_its_size(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume)), false, ItemReader::read('JACK DANIELS 0,7L', 'szt.'));

        self::assertSame(PurchasedItemStatus::Assigned, $decision->status);
        self::assertSame('0.7', $decision->packageSize);
    }

    public function test_it_uses_the_size_the_alias_remembers_when_the_name_has_none(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume), '0.5'), false, ItemReader::read('Piwo jasne', 'szt.'));

        self::assertSame(PurchasedItemStatus::Assigned, $decision->status);
        self::assertSame('0.5', $decision->packageSize);
    }

    public function test_it_prefers_what_the_item_itself_says_over_what_an_alias_remembers(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume), '0.7'), false, ItemReader::read('Whisky', 'l'));

        self::assertSame('1', $decision->packageSize);
        self::assertSame(PurchasedItemStatus::Assigned, $decision->status);
    }

    public function test_it_only_suggests_when_the_package_size_is_unknown(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume)), false, ItemReader::read('Whisky Demo', 'szt.'));

        self::assertSame(PurchasedItemStatus::Suggested, $decision->status);
        self::assertNull($decision->packageSize);
    }

    public function test_it_only_suggests_a_size_that_only_a_multipack_says(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume)), false, ItemReader::read('Piwo 6x0,5L', 'zgrz.'));

        self::assertSame(PurchasedItemStatus::Suggested, $decision->status);
        self::assertSame('3', $decision->packageSize);
    }

    public function test_it_only_suggests_when_the_weak_reading_and_the_alias_disagree(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume), '0.5'), false, ItemReader::read('Piwo 6x0,5L', 'zgrz.'));

        self::assertSame(PurchasedItemStatus::Suggested, $decision->status);
    }

    public function test_it_never_assigns_on_a_size_read_from_a_unit_of_unknown_count_even_when_the_alias_agrees(): void
    {
        // "Jack Daniels 1L | CO | 1": the alias was confirmed from the "szt" row with size 1 and agrees with the weak reading.
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume), '1'), false, ItemReader::read('Jack Daniels 1L', 'CO'));

        self::assertSame(PurchasedItemStatus::Suggested, $decision->status);
    }

    public function test_it_never_assigns_on_a_size_from_the_model_for_a_unit_of_unknown_count_even_when_it_agrees(): void
    {
        $product = self::product(ProductMeasure::Volume);
        $answer = new ItemInterpretation('k', $product->getId()->toRfc4122(), InterpretationConfidence::High, '1');

        $decision = MatchDecision::fromInterpretation($product, $answer, ItemReader::read('Jack Daniels 1L', 'CO'));

        self::assertNotNull($decision);
        self::assertSame(PurchasedItemStatus::Suggested, $decision->status);
    }

    public function test_it_assigns_once_the_sellers_multiplier_for_the_unit_is_known(): void
    {
        $reading = ItemReader::withUnitMultiplier(ItemReader::read('Jack Daniels 1L', 'CO'), 6);

        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume), '1'), false, $reading);

        self::assertSame(PurchasedItemStatus::Assigned, $decision->status);
        self::assertSame('6', $decision->packageSize, 'The learned multiplier wins over the size an alias remembers for one piece.');
    }

    public function test_it_only_suggests_when_the_aliases_of_an_item_point_to_different_products(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Volume)), true, ItemReader::read('JACK DANIELS 0,7L', 'szt.'));

        self::assertSame(PurchasedItemStatus::Suggested, $decision->status);
        self::assertSame('0.7', $decision->packageSize);
    }

    public function test_it_only_suggests_a_size_of_another_measure_than_the_product(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Mass)), false, ItemReader::read('Sok 1l', 'szt.'));

        self::assertSame(PurchasedItemStatus::Suggested, $decision->status);
        self::assertNull($decision->packageSize);
    }

    public function test_it_assigns_a_product_without_a_unit_with_no_package_size(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::None)), false, ItemReader::read('Czynsz', 'mies.'));

        self::assertSame(PurchasedItemStatus::Assigned, $decision->status);
        self::assertNull($decision->packageSize);
    }

    public function test_it_counts_one_piece_for_a_product_counted_in_pieces(): void
    {
        $decision = MatchDecision::fromAlias($this->alias(self::product(ProductMeasure::Count)), false, ItemReader::read('Serwetki', 'szt.'));

        self::assertSame('1', $decision->packageSize);
        self::assertSame(PurchasedItemStatus::Assigned, $decision->status);
    }

    public function test_it_assigns_what_the_model_is_sure_of_and_suggests_what_it_is_not(): void
    {
        $product = self::product(ProductMeasure::Volume);
        $reading = ItemReader::read('JACK DANIELS 0,7L', 'szt.');

        $high = MatchDecision::fromInterpretation($product, new ItemInterpretation('k', $product->getId()->toRfc4122(), InterpretationConfidence::High, '0.7'), $reading);
        $medium = MatchDecision::fromInterpretation($product, new ItemInterpretation('k', $product->getId()->toRfc4122(), InterpretationConfidence::Medium, '0.7'), $reading);
        $low = MatchDecision::fromInterpretation($product, new ItemInterpretation('k', $product->getId()->toRfc4122(), InterpretationConfidence::Low, '0.7'), $reading);

        self::assertSame(PurchasedItemStatus::Assigned, $high?->status);
        self::assertSame(PurchasedItemStatus::Suggested, $medium?->status);
        self::assertNull($low, 'A guess is no match.');
    }

    public function test_it_takes_the_size_of_the_model_only_when_the_text_has_none_and_trusts_its_size_only_that_way(): void
    {
        $product = self::product(ProductMeasure::Volume);
        $interpretation = static fn (?string $size): ItemInterpretation => new ItemInterpretation('k', $product->getId()->toRfc4122(), InterpretationConfidence::High, $size);

        $fromTheModel = MatchDecision::fromInterpretation($product, $interpretation('0.50'), ItemReader::read('Piwo jasne', 'szt.'));
        self::assertNotNull($fromTheModel);
        self::assertSame('0.5', $fromTheModel->packageSize);
        self::assertSame(PurchasedItemStatus::Assigned, $fromTheModel->status);

        // The model reads 0.7 where the unit says 1 per litre: the unit wins.
        $unitWins = MatchDecision::fromInterpretation($product, $interpretation('0.7'), ItemReader::read('Piwo', 'l'));
        self::assertSame('1', $unitWins?->packageSize);

        // The model reads a size, the text only a weak multipack that differs: for a person.
        $disagree = MatchDecision::fromInterpretation($product, $interpretation('0.5'), ItemReader::read('Piwo 6x0,5L', 'zgrz.'));
        self::assertSame(PurchasedItemStatus::Suggested, $disagree?->status);

        // No size from anyone: for a person. Nonsense sizes are no size.
        foreach ([null, '0', '-1', 'abc'] as $size) {
            $none = MatchDecision::fromInterpretation($product, $interpretation($size), ItemReader::read('Piwo jasne', 'szt.'));
            self::assertNotNull($none);
            self::assertSame(PurchasedItemStatus::Suggested, $none->status);
            self::assertNull($none->packageSize);
        }
    }

    private static function product(ProductMeasure $measure): Product
    {
        return Product::create(Uuid::v7(), 'Product', null, $measure, null, false, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));
    }

    private function alias(Product $product, ?string $size = null): ProductAlias
    {
        return new ProductAlias(Uuid::v7(), AliasKind::SellerCode, 'KEY', $product, $size, new \DateTimeImmutable('2026-11-01 10:00:00'));
    }
}
