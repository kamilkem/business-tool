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
        yield 'a size on a pack unit' => ['Cola 2L', 'kpl', '2', ProductMeasure::Volume, SizeSource::Name, true];
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

    public function test_it_knows_a_piece_unit_from_one_it_does_not(): void
    {
        self::assertTrue(ItemReader::read('Cola', 'szt.')->pieceUnit);
        self::assertTrue(ItemReader::read('Cola', null)->pieceUnit);
        self::assertFalse(ItemReader::read('Czynsz', 'mies.')->pieceUnit);
        self::assertFalse(ItemReader::read('Wino', 'l')->pieceUnit);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nameKeys(): iterable
    {
        yield 'size and ABV removed' => ['JACK DANIELS 0,7L 40%', 'jack daniels'];
        yield 'an abbreviation stays' => ['J.DANIELS 1L', 'j daniels'];
        yield 'word order and extra words stay' => ["Whiskey Jack Daniel's Old No.7 700ml", 'whiskey jack daniels old no 7'];
        yield 'a flavour stays' => ["Jack Daniel's Honey 0,7l", 'jack daniels honey'];
        yield 'multipack removed' => ['Piwo Demo 6x0,5L zgrz.', 'piwo demo'];
        yield 'the same without the pack' => ['Piwo Demo 24x330ml', 'piwo demo'];
        yield 'diacritics folded' => ['Wódka Żołądkowa Gorzka 0,5L 40%', 'wodka zoladkowa gorzka'];
        yield 'nothing left' => ['0,5l', ''];
    }

    #[DataProvider('nameKeys')]
    public function test_it_normalizes_the_name_to_a_key_without_sizes(string $name, string $key): void
    {
        self::assertSame($key, ItemReader::nameKey($name));
    }

    public function test_it_keeps_the_size_in_the_full_key(): void
    {
        self::assertSame('jack daniels 0 7l 40', ItemReader::fullKey('JACK DANIELS 0,7L 40%'));
        self::assertNotSame(ItemReader::fullKey('Jack Daniels 1L'), ItemReader::fullKey('Jack Daniels 0,7L'));
    }
}
