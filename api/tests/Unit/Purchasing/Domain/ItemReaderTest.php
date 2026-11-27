<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Matching\SizeSource;
use App\Purchasing\Domain\Model\ProductMeasure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading the package size and the name key (ADR 0042 §5). All names are synthetic: the forms the
 * spike's issue (#233) lists, not names from a real invoice.
 */
#[CoversClass(ItemReader::class)]
final class ItemReaderTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string, ?ProductMeasure, SizeSource, bool}> name, unit, size, measure, source, strong */
    public static function readings(): iterable
    {
        yield '0,7L with ABV' => ['JACK DANIELS 0,7L 40%', 'szt.', '0.7', ProductMeasure::Volume, SizeSource::Name, true];
        yield '700ml' => ["Whiskey Jack Daniel's Old No.7 700ml", 'szt.', '0.7', ProductMeasure::Volume, SizeSource::Name, true];
        yield '1L' => ['J.DANIELS 1L', 'szt', '1', ProductMeasure::Volume, SizeSource::Name, true];
        yield 'a point and a space' => ['Wódka Demo 0.7 l', 'szt.', '0.7', ProductMeasure::Volume, SizeSource::Name, true];
        yield '70cl' => ['Wódka Demo 70cl', 'but.', '0.7', ProductMeasure::Volume, SizeSource::Name, true];
        yield 'keg on a piece unit' => ['Piwo jasne KEG 30L', 'szt.', '30', ProductMeasure::Volume, SizeSource::Name, true];
        yield 'a keg row counted in litres' => ['Piwo jasne KEG 30L', 'l', '1', ProductMeasure::Volume, SizeSource::Unit, true];
        yield 'draught beer in litres' => ['Piwo jasne', 'l', '1', ProductMeasure::Volume, SizeSource::Unit, true];
        yield 'meat in kg' => ['Schab wieprzowy b/k', 'kg', '1', ProductMeasure::Mass, SizeSource::Unit, true];
        yield 'a unit in grams' => ['Przyprawa', 'g', '0.001', ProductMeasure::Mass, SizeSource::Unit, true];
        yield 'multipack 6x0,5L' => ['Piwo Demo 6x0,5L', 'zgrz.', '3', ProductMeasure::Volume, SizeSource::Multipack, false];
        yield 'multipack 24x330ml' => ['Piwo Demo 24x330ml', 'kart.', '7.92', ProductMeasure::Volume, SizeSource::Multipack, false];
        yield 'multipack with the count last' => ['Piwo Demo 0,5l x 20', 'kart.', '10', ProductMeasure::Volume, SizeSource::Multipack, false];
        yield 'multipack with spaces' => ['Cola Demo zgrz. 6 x 1,5 l', 'szt.', '9', ProductMeasure::Volume, SizeSource::Multipack, false];
        yield '1kg' => ['Cukier 1kg', 'szt.', '1', ProductMeasure::Mass, SizeSource::Name, true];
        yield '500 g' => ['Mąka pszenna 500 g', 'szt.', '0.5', ProductMeasure::Mass, SizeSource::Name, true];
        yield '25 dkg' => ['Szynka 25 dkg', 'szt.', '0.25', ProductMeasure::Mass, SizeSource::Name, true];
        yield 'a size only in the unit text' => ['Tonik Demo', 'op. 0,2l', '0.2', ProductMeasure::Volume, SizeSource::UnitText, true];
        yield 'LT as litres' => ['Wino 0.75 LT', 'but', '0.75', ProductMeasure::Volume, SizeSource::Name, true];
        yield 'the same size twice' => ['Piwo Demo 0,5L 0,5l', 'szt.', '0.5', ProductMeasure::Volume, SizeSource::Name, true];
        yield 'a miniature' => ['Gin Demo 5cl miniatura', 'szt.', '0.05', ProductMeasure::Volume, SizeSource::Name, true];
        yield 'a size on a unit of unknown count is weak' => ['Cola 2L', 'kpl', '2', ProductMeasure::Volume, SizeSource::Name, false];
        yield 'CO is the seller\'s word: weak' => ['Jack Daniels 1L', 'CO', '1', ProductMeasure::Volume, SizeSource::Name, false];
        yield 'a carton is not one piece' => ['Piwo 0,5L', 'kart.', '0.5', ProductMeasure::Volume, SizeSource::Name, false];
        yield 'a pack unit' => ['Piwo 0,5L', 'op.', '0.5', ProductMeasure::Volume, SizeSource::Name, false];
        yield 'a bottle is one piece' => ['Jack Daniels 1L', 'szt', '1', ProductMeasure::Volume, SizeSource::Name, true];
        yield 'no size: bare numbers are not sizes' => ['Whisky 12 YO Old No.7 40%', 'szt.', null, null, SizeSource::None, false];
        yield 'no size: not goods' => ['Czynsz najmu lokalu', 'mies.', null, null, SizeSource::None, false];
        yield 'two different sizes' => ['Piwo 0,5l (kaucja 0,3l)', 'szt.', null, null, SizeSource::Ambiguous, false];
        yield 'a number followed by x' => ['Smak No.7 4x', 'szt.', null, null, SizeSource::None, false];
        yield 'a size too large to be one' => ['Beczka 99999l', 'szt.', null, null, SizeSource::None, false];
        yield 'more decimals than a gram' => ['Szafran 0,0005g', 'szt.', null, null, SizeSource::None, false];
    }

    #[DataProvider('readings')]
    public function test_it_reads_the_package_size_from_the_unit_and_the_name(string $name, ?string $unit, ?string $size, ?ProductMeasure $measure, SizeSource $source, bool $strong): void
    {
        $reading = ItemReader::read($name, $unit);

        self::assertSame($size, $reading->packageSize);
        self::assertSame($measure, $reading->measure);
        self::assertSame($source, $reading->source);
        self::assertSame($strong, $reading->strong);
    }

    /** @return iterable<string, array{string, ?string, ProductMeasure, ?string}> */
    public static function sizesForAConfirmedProduct(): iterable
    {
        yield 'a strong size of the products measure' => ['JACK DANIELS 0,7L 40%', 'szt.', ProductMeasure::Volume, '0.7'];
        yield 'a measure unit' => ['Piwo z kega', 'l', ProductMeasure::Volume, '1'];
        yield 'a size of another measure' => ['JACK DANIELS 0,7L', 'szt.', ProductMeasure::Mass, null];
        yield 'a weak size: a multipack' => ['Piwo 6x0,5L', 'szt.', ProductMeasure::Volume, null];
        yield 'a weak size: a unit of unknown count' => ['Jack Daniels 1L', 'CO', ProductMeasure::Volume, null];
        yield 'no size, counted in pieces on a piece unit' => ['Serwetki białe', 'szt.', ProductMeasure::Count, '1'];
        yield 'no size, volume' => ['Cola', 'szt.', ProductMeasure::Volume, null];
        yield 'no size, counted in pieces on a unit of unknown count' => ['Serwetki białe', 'kart.', ProductMeasure::Count, null];
        yield 'ambiguous sizes' => ['Piwo 0,5l (kaucja 0,3l)', 'szt.', ProductMeasure::Count, null];
        yield 'a product with no unit' => ['Czynsz', 'mies.', ProductMeasure::None, null];
    }

    #[DataProvider('sizesForAConfirmedProduct')]
    public function test_it_starts_a_confirmed_items_package_size_from_what_the_text_strongly_says(string $name, ?string $unit, ProductMeasure $measure, ?string $size): void
    {
        self::assertSame($size, ItemReader::read($name, $unit)->packageSizeFor($measure));
    }

    public function test_it_knows_whether_a_unit_counts_one_piece_or_an_unknown_number(): void
    {
        foreach ([['Cola 1l', 'szt.'], ['Cola 1l', null], ['Cola 1l', 'but'], ['Wino', 'l'], ['Tonik', 'op. 0,2l']] as [$name, $unit]) {
            self::assertTrue(ItemReader::read($name, $unit)->unitCountKnown, \sprintf('%s | %s', $name, (string) $unit));
        }
        foreach ([['Cola 1l', 'CO'], ['Cola 1l', 'kart.'], ['Cola 1l', 'op.'], ['Cola 1l', 'BOX'], ['Piwo 6x0,5L', 'zgrz.']] as [$name, $unit]) {
            self::assertFalse(ItemReader::read($name, $unit)->unitCountKnown, \sprintf('%s | %s', $name, $unit));
        }
    }

    public function test_it_multiplies_a_size_on_a_unit_of_unknown_count_by_the_sellers_multiplier(): void
    {
        $co = ItemReader::read('Jack Daniels 1L', 'CO');

        $withMultiplier = ItemReader::withUnitMultiplier($co, 6);

        self::assertSame('6', $withMultiplier->packageSize);
        self::assertTrue($withMultiplier->strong);
        self::assertSame(SizeSource::SellerUnit, $withMultiplier->source);
        self::assertSame('4.2', ItemReader::withUnitMultiplier(ItemReader::read('Whisky 0,7L', 'karton'), 6)->packageSize);
        // A unit whose count is known, or a reading without a size, is left as it is.
        $szt = ItemReader::read('Jack Daniels 1L', 'szt');
        self::assertSame($szt, ItemReader::withUnitMultiplier($szt, 6));
        $none = ItemReader::read('Czynsz', 'CO');
        self::assertSame($none, ItemReader::withUnitMultiplier($none, 6));
    }

    /** @return iterable<string, array{string, string, string, ?int}> name, unit, confirmed size, the multiplier learned */
    public static function confirmations(): iterable
    {
        yield 'a case of 6 one-litre bottles' => ['Jack Daniels 1L', 'CO', '6', 6];
        yield 'a case of 24 half-litre bottles' => ['Piwo 0,5L', 'kart.', '12', 24];
        yield 'one piece' => ['Piwo 0,5L', 'op.', '0.5', 1];
        yield 'a multipack named in the text counts once' => ['Piwo 6x0,5L', 'zgrz.', '3', 1];
        yield 'two multipacks' => ['Piwo 6x0,5L', 'zgrz.', '6', 2];
        yield 'not a whole multiple' => ['Jack Daniels 1L', 'CO', '2.5', null];
        yield 'smaller than the size read' => ['Jack Daniels 1L', 'CO', '0.5', null];
        yield 'a unit whose count is known teaches nothing' => ['Jack Daniels 1L', 'szt', '6', null];
        yield 'no size in the text' => ['Jack Daniels', 'CO', '6', null];
        yield 'two sizes in the text' => ['Piwo 0,5l (kaucja 0,3l)', 'CO', '6', null];
    }

    #[DataProvider('confirmations')]
    public function test_it_learns_how_many_pieces_a_unit_holds_from_what_the_owner_confirmed(string $name, string $unit, string $confirmed, ?int $multiplier): void
    {
        self::assertSame($multiplier, ItemReader::multiplierOf(ItemReader::read($name, $unit), $confirmed));
    }

    public function test_it_remembers_a_unit_as_the_seller_wrote_it(): void
    {
        self::assertSame('co', ItemReader::unitKeyOf(' CO. '));
        self::assertSame('karton', ItemReader::unitKeyOf('Karton'));
        self::assertNull(ItemReader::unitKeyOf(null));
        self::assertNull(ItemReader::unitKeyOf(' . '));
    }

    public function test_it_knows_a_piece_unit_from_one_it_does_not(): void
    {
        self::assertTrue(ItemReader::read('Cola', 'szt.')->pieceUnit);
        self::assertTrue(ItemReader::read('Cola', null)->pieceUnit);
        self::assertFalse(ItemReader::read('Czynsz', 'mies.')->pieceUnit);
        self::assertFalse(ItemReader::read('Cola', 'CO')->pieceUnit);
        self::assertFalse(ItemReader::read('Wino', 'l')->pieceUnit);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nameKeys(): iterable
    {
        yield 'size removed, ABV kept as one token' => ['JACK DANIELS 0,7L 40%', 'jack daniels abv40'];
        yield 'an abbreviation stays' => ['J.DANIELS 1L', 'j daniels'];
        yield 'word order and extra words stay' => ["Whiskey Jack Daniel's Old No.7 700ml", 'whiskey jack daniels old no 7'];
        yield 'a flavour stays' => ["Jack Daniel's Honey 0,7l", 'jack daniels honey'];
        yield 'multipack removed' => ['Piwo Demo 6x0,5L zgrz.', 'piwo demo'];
        yield 'the same without the pack' => ['Piwo Demo 24x330ml', 'piwo demo'];
        yield 'diacritics folded' => ['Wódka Żołądkowa Gorzka 0,5L 40%', 'wodka zoladkowa gorzka abv40'];
        yield 'a decimal ABV' => ['PIWO LITOVEL BLACK LEMON 0,5% 0,5l BUT ZWROTNA', 'piwo litovel black lemon abv0p5 zwrotna'];
        yield 'a whole ABV' => ['PIWO LITOVEL BLACK LEMON 4% 0,5l BUT ZWROTNA', 'piwo litovel black lemon abv4 zwrotna'];
        yield 'an age stays' => ['Whisky Demo 12 YO 0,7l', 'whisky demo 12 yo'];
        yield 'a vintage stays' => ['Wino Demo 2015 0,75l', 'wino demo 2015'];
        yield 'a discount is no ABV' => ['Piwo Demo -20% 0,5l', 'piwo demo 20'];
        yield 'nothing left' => ['0,5l', ''];
    }

    #[DataProvider('nameKeys')]
    public function test_it_normalizes_the_name_to_a_key_without_sizes(string $name, string $key): void
    {
        self::assertSame($key, ItemReader::nameKey($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function sameProductDifferentlyPrinted(): iterable
    {
        yield 'a space before the percent' => ['Jack Daniels 0,7l 40%', 'Jack Daniels 0,7l 40 %'];
        yield 'with vol' => ['Jack Daniels 0,7l 40%', 'Jack Daniels 0,7l 40% vol'];
        yield 'with vol and a dot' => ['Jack Daniels 0,7l 40%', 'JACK DANIELS 0,7L 40 % VOL.'];
        yield 'a decimal with a zero' => ['Jack Daniels 0,7l 40%', 'Jack Daniels 0,7l 40,0%'];
        yield 'a decimal point' => ['Piwo Demo 0,5% 0,5l', 'Piwo Demo 0.50 % 0,5l'];
        yield 'proc' => ['Jack Daniels 0,7l 40%', 'Jack Daniels 0,7l 40 proc.'];
        yield 'another size' => ['Jack Daniels 0,7l 40%', 'Jack Daniels 1L 40%'];
        yield 'a multipack' => ['Piwo Demo 4% 0,5l', 'Piwo Demo 4% 6x0,5L zgrz.'];
    }

    #[DataProvider('sameProductDifferentlyPrinted')]
    public function test_it_gives_one_key_and_one_abv_to_an_abv_printed_differently(string $a, string $b): void
    {
        self::assertSame(ItemReader::nameKey($a), ItemReader::nameKey($b));
        self::assertSame(ItemReader::abvOf($a), ItemReader::abvOf($b));
        self::assertFalse(ItemReader::abvContradicts($a, $b));
    }

    /** @return iterable<string, array{string, string}> */
    public static function differentProducts(): iterable
    {
        yield 'a beer of 4% and one of 0,5%' => ['PIWO LITOVEL BLACK LEMON 4% 0,5l BUT ZWROTNA', 'PIWO LITOVEL BLACK LEMON 0,5% 0,5l BUT ZWROTNA'];
        yield 'a vodka of 37,5% and one of 40%' => ['Wodka Demo 37,5% 0,5l', 'Wodka Demo 40% 0,5l'];
        yield 'a flavour' => ["Jack Daniel's Honey 0,7l 35%", "Jack Daniel's 0,7l 35%"];
        yield 'a flavour without ABV' => ["Jack Daniel's Honey 0,7l", "Jack Daniel's 0,7l"];
        yield 'an age' => ['Whisky Demo 12 YO 0,7l 40%', 'Whisky Demo 18 YO 0,7l 40%'];
        yield 'a vintage' => ['Wino Demo 2015 0,75l 13%', 'Wino Demo 2018 0,75l 13%'];
        yield 'with and without ABV' => ['Piwo Demo 4% 0,5l', 'Piwo Demo 0,5l'];
    }

    #[DataProvider('differentProducts')]
    public function test_it_keeps_names_that_differ_in_abv_or_variant_apart_in_the_key(string $a, string $b): void
    {
        self::assertNotSame(ItemReader::nameKey($a), ItemReader::nameKey($b));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function abvs(): iterable
    {
        yield 'whole' => ['40', 'Jack Daniels 0,7L 40%'];
        yield 'decimal comma' => ['0.5', 'Piwo 0,5% 0,5l'];
        yield 'trailing zero' => ['4', 'Piwo 4,0% 0,5l'];
        yield 'proc' => ['4.5', 'Piwo 4,5 proc'];
        yield 'none' => [null, 'Jack Daniels 0,7L'];
        yield 'a size is no ABV' => [null, 'Cola 0,5l'];
        yield 'two different ones are ambiguous' => [null, 'Mix 4% 0,5% 0,5l'];
        yield 'the same twice' => ['4', 'Piwo 4% (4 % vol) 0,5l'];
        yield 'above 100 is none' => [null, 'Promo 150% 0,5l'];
        yield 'a discount is none' => [null, 'Piwo -20% 0,5l'];
        yield 'a size next to it' => ['40', 'Jack Daniels 700ml 40% vol'];
    }

    #[DataProvider('abvs')]
    public function test_it_reads_the_alcohol_strength_of_a_name(?string $abv, string $name): void
    {
        self::assertSame($abv, ItemReader::abvOf($name));
    }

    public function test_it_says_two_names_contradict_only_when_both_state_a_different_abv(): void
    {
        self::assertTrue(ItemReader::abvContradicts('PIWO LITOVEL BLACK LEMON 4% 0,5l', 'Piwo Litovel Black Lemon 0,5%'));
        self::assertFalse(ItemReader::abvContradicts('Piwo Litovel Black Lemon 4% 0,5l', 'Piwo Litovel Black Lemon'), 'A product that states no ABV contradicts nothing.');
        self::assertFalse(ItemReader::abvContradicts('Piwo Litovel Black Lemon 0,5l', 'Piwo Litovel Black Lemon 0,5%'), 'An item that states no ABV contradicts nothing.');
        self::assertFalse(ItemReader::abvContradicts('Mix 4% 0,5%', 'Mix 4%'), 'An ambiguous name is not read.');
    }

    public function test_it_keeps_the_size_in_the_full_key(): void
    {
        self::assertSame('jack daniels 0 7l 40', ItemReader::fullKey('JACK DANIELS 0,7L 40%'));
        self::assertNotSame(ItemReader::fullKey('Jack Daniels 1L'), ItemReader::fullKey('Jack Daniels 0,7L'));
    }
}
