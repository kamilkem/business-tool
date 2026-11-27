<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Purchasing\Domain\Model\PackageSize;
use App\Purchasing\Domain\Model\ProductMeasure;

/**
 * Reads an item's package size from its name and unit, and normalizes its name (ADR 0042 §5). Pure
 * rules, no database; what the rules cannot read is null, never a guess.
 *
 * The size is base units (L, kg) per ONE unit of the item's quantity (ADR 0041 §3):
 * 1. the unit is a measure (`l`, `ml`, `kg`, ...): the unit's factor, the name's size is ignored (30 `l`
 *    of "KEG 30L" draught beer has size 1);
 * 2. one size in the name; a multipack (`6x0,5L`, `0,5l x 6`) is a bundle: its count and the size of one
 *    package, and its total is what is compared (ADR 0048);
 * 3. a size in the unit's text (`op. 0,2l`) when the name has none;
 * 4. otherwise none. Two different sizes in one name are ambiguous: none.
 * A bare number (`0,7`, `No.7`, `12 YO`, `40%`) is never a size; an ABV is read on its own (`abvOf()`).
 *
 * Arithmetic is exact integer arithmetic on millionths of the base unit, never floats (ADR 0040 §4);
 * sizes above 10 000 or with more than six decimals are not read.
 */
final class ItemReader
{
    private const int SCALE = 1_000_000;
    private const int MAX_MICROS = 10_000 * self::SCALE;
    private const int MAX_MULTIPLIER = 999;

    /** Unit text => [micro-units of the base unit per one of it, measure]. */
    private const array FACTORS = [
        'ml' => [1_000, ProductMeasure::Volume], 'cl' => [10_000, ProductMeasure::Volume], 'dl' => [100_000, ProductMeasure::Volume],
        'l' => [1_000_000, ProductMeasure::Volume], 'lt' => [1_000_000, ProductMeasure::Volume], 'ltr' => [1_000_000, ProductMeasure::Volume],
        'hl' => [100_000_000, ProductMeasure::Volume],
        'mg' => [1, ProductMeasure::Mass], 'g' => [1_000, ProductMeasure::Mass], 'gr' => [1_000, ProductMeasure::Mass],
        'dkg' => [10_000, ProductMeasure::Mass], 'dag' => [10_000, ProductMeasure::Mass], 'kg' => [1_000_000, ProductMeasure::Mass],
    ];

    /**
     * Units of one single piece or container: the quantity counts them one by one. Every other unit that
     * is not a measure (`CO`, `kart.`, `op.`, `zgrz.`, `kpl.`, `paczka`, a seller's own) holds an unknown
     * number of pieces (ADR 0043).
     */
    private const array SINGLE_UNITS = [
        'szt', 'sztuk', 'sztuka', 'but', 'butelka', 'fl', 'puszka', 'pusz', 'keg', 'kega', 'beczka', 'worek', 'wor', 'kanister', 'kan',
    ];

    /**
     * An alcohol strength: `40%`, `40 %`, `40,0%`, `40% vol`, `4 proc.`, `40 vol`. At most three digits and three decimals;
     * not after a digit, a separator or a minus (`-20%` is a discount).
     */
    private const string ABV = '(?<![\d.,\-])(?<abv>\d{1,3}(?:[.,]\d{1,3})?)\s*(?:%|proc(?:ent\w*)?(?![a-z0-9])|vol(?![a-z0-9]))(?:\s*(?:vol|obj|alk)(?![a-z0-9])\.?)?';

    private const string SIZE = '(?<n>\d+(?:[.,]\d+)?)\s*(?<u>ml|cl|dl|hl|ltr|lt|litr\w*|l|mg|dkg|dag|kg|gr|g)(?![a-z0-9])';

    private function __construct()
    {
    }

    public static function read(string $name, ?string $unit): ItemReading
    {
        $unitKey = self::unitKey($unit);
        $pieceUnit = '' === $unitKey || \in_array($unitKey, self::SINGLE_UNITS, true);

        // 1. The unit is a measure.
        if (isset(self::FACTORS[$unitKey])) {
            [$factor, $measure] = self::FACTORS[$unitKey];

            return new ItemReading(self::toDecimal($factor), $measure, SizeSource::Unit, true, false, true);
        }

        // 2. The name, 3. the unit's text.
        $sizes = self::sizesIn($name);
        $fromUnitText = false;
        if ([] === $sizes && null !== $unit) {
            $sizes = self::sizesIn($unit);
            $fromUnitText = [] !== $sizes;
        }
        if ([] === $sizes) {
            return new ItemReading(null, null, SizeSource::None, false, $pieceUnit, $pieceUnit);
        }

        $totals = [];
        foreach ($sizes as $size) {
            $totals[$size['measure']->value.':'.$size['micros']] = $size;
        }
        if (\count($totals) > 1) {
            return new ItemReading(null, null, SizeSource::Ambiguous, false, $pieceUnit, $pieceUnit);
        }
        $size = array_values($totals)[0];
        $multipack = $size['multiplier'] > 1;

        return new ItemReading(
            self::toDecimal($size['micros']),
            $size['measure'],
            $multipack ? SizeSource::Multipack : ($fromUnitText ? SizeSource::UnitText : SizeSource::Name),
            !$multipack && ($pieceUnit || $fromUnitText),
            $pieceUnit,
            $pieceUnit || $fromUnitText,
            $multipack ? $size['multiplier'] : null,
            $multipack ? self::toDecimal(intdiv($size['micros'], $size['multiplier'])) : null,
        );
    }

    /**
     * The reading with the seller's multiplier for its unit (ADR 0043): a size read from the name on a
     * unit of unknown count, times the number of pieces the owner confirmed that unit holds, is the size
     * per unit of the quantity, and as sure as the owner's confirmation. Readings of a unit whose count is
     * known, or without a size, are returned as they are.
     */
    public static function withUnitMultiplier(ItemReading $reading, int $multiplier): ItemReading
    {
        if ($reading->unitCountKnown || !$reading->hasSize() || $multiplier < 1 || SizeSource::Ambiguous === $reading->source) {
            return $reading;
        }
        $micros = self::microsOf((string) $reading->packageSize);
        if (null === $micros || $micros * $multiplier > self::MAX_MICROS) {
            return $reading;
        }

        // The unit holds `multiplier` of what the name states (itself a multipack, perhaps): that is a bundle (ADR 0048).
        $count = ($reading->bundleCount ?? 1) * $multiplier;
        $bundled = $count >= PackageSize::MIN_BUNDLE_COUNT && $count <= PackageSize::MAX_BUNDLE_COUNT;

        return new ItemReading(
            self::toDecimal($micros * $multiplier),
            $reading->measure,
            SizeSource::SellerUnit,
            true,
            $reading->pieceUnit,
            true,
            $bundled ? $count : null,
            $bundled ? ($reading->singleSize ?? $reading->packageSize) : null,
        );
    }

    /**
     * How many of the read size the confirmed package size is, for a unit of unknown count: `1.000000` read
     * and `6` confirmed is 6. Null when the reading is not a size on such a unit, or the confirmed size is
     * not a whole multiple (at least 1) of it: nothing is learned then.
     */
    public static function multiplierOf(ItemReading $reading, string $confirmedSize): ?int
    {
        if ($reading->unitCountKnown || !$reading->hasSize() || SizeSource::Ambiguous === $reading->source) {
            return null;
        }
        $read = self::microsOf((string) $reading->packageSize);
        $confirmed = self::microsOf($confirmedSize);
        if (null === $read || null === $confirmed || $read <= 0 || $confirmed < $read || 0 !== $confirmed % $read) {
            return null;
        }
        $multiplier = intdiv($confirmed, $read);

        return $multiplier <= self::MAX_MULTIPLIER ? $multiplier : null;
    }

    /** A unit as a seller's unit is remembered by: lower case, no diacritics, no trailing dot or space; null when there is none. */
    public static function unitKeyOf(?string $unit): ?string
    {
        $key = self::unitKey($unit);

        return '' === $key ? null : mb_substr($key, 0, 64);
    }

    /** A decimal string as millionths, exactly; null when it has more than six decimals, is negative or too large. */
    private static function microsOf(string $decimal): ?int
    {
        if (1 !== preg_match('/^(\d{1,5})(?:\.(\d{1,6}))?$/D', $decimal, $parts)) {
            return null;
        }

        return (int) $parts[1] * self::SCALE + (int) str_pad($parts[2] ?? '', 6, '0');
    }

    /**
     * The name key: lower case, diacritics folded, apostrophes dropped, punctuation to spaces, and sizes,
     * multipacks and packaging words removed, so "JACK DANIELS 0,7L 40%" and "Jack Daniels 1L 40 %" are
     * one key. **The ABV stays, as one token** (`abv40`, `abv0p5`, whatever way it was printed), because a
     * beer of 4% and one of 0,5% are two products (ADR 0047); a name without one has none. Word order and
     * extra words stay: they are left to the aliases the owner confirms and to the model.
     */
    public static function nameKey(string $name): string
    {
        $text = self::fold($name);
        $text = preg_replace('/(?<![\d.,])\d{1,3}\s*[x*×]\s*(?=\d)/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<![\d.,])\d+(?:[.,]\d+)?\s*(?:ml|cl|dl|hl|ltr|lt|litr\w*|l|mg|dkg|dag|kg|gr|g)(?![a-z0-9])(\s*[x*×]\s*\d{1,3}(?![\d.,a-z]))?/u', ' ', $text) ?? $text;
        $text = preg_replace_callback('/'.self::ABV.'/u', static function (array $match): string {
            $abv = self::canonicalAbv($match['abv']);

            return null === $abv ? ' ' : ' abv'.str_replace('.', 'p', $abv).' ';
        }, $text) ?? $text;
        $text = preg_replace('/\b(?:szt|sztuk|zgrz|zgrzewka|kart|karton|krt|op|opak|but|butelka|puszka|keg|kega|fl)\b/u', ' ', $text) ?? $text;

        return self::words($text);
    }

    /**
     * The alcohol strength the name states, in percent as a decimal string with a point and no trailing zeros
     * (`40`, `0.5`): "40%", "40 %", "40,0% vol" and "40 proc." are all `40`. Null when the name states none, or
     * two different ones (ambiguous: nothing is guessed). A percent above 100 is no strength.
     */
    public static function abvOf(string $name): ?string
    {
        if (false === preg_match_all('/'.self::ABV.'/u', self::fold($name), $matches)) {
            return null;
        }
        $found = [];
        foreach ($matches['abv'] as $text) {
            $abv = self::canonicalAbv($text);
            if (null !== $abv) {
                $found[$abv] = true;
            }
        }

        return 1 === \count($found) ? (string) array_key_first($found) : null;
    }

    /**
     * Whether both names state an alcohol strength and the two differ: then they are two products, whatever
     * else they share (ADR 0047). A name that states none, or an ambiguous one, contradicts nothing.
     */
    public static function abvContradicts(string $itemName, string $productName): bool
    {
        $item = self::abvOf($itemName);
        $product = self::abvOf($productName);

        return null !== $item && null !== $product && $item !== $product;
    }

    /** `40,0` → `40`, `0,50` → `0.5`, `04` → `4`; null above 100. */
    private static function canonicalAbv(string $text): ?string
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $text), 2), 2, '');
        $whole = (string) (int) $whole;
        $fraction = rtrim($fraction, '0');
        if ((int) $whole > 100 || (100 === (int) $whole && '' !== $fraction)) {
            return null;
        }

        return '' === $fraction ? $whole : $whole.'.'.$fraction;
    }

    /** The full normalized name, sizes kept: what a seller's exact name is remembered by. */
    public static function fullKey(string $name): string
    {
        return self::words(self::fold($name));
    }

    private static function words(string $text): string
    {
        $text = preg_replace('/[^a-z0-9]+/u', ' ', str_replace("'", '', $text)) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'é' => 'e', 'è' => 'e', 'ü' => 'u', 'ö' => 'o', 'ä' => 'a', 'ß' => 'ss', 'ç' => 'c', 'ñ' => 'n', '’' => "'",
        ]);
    }

    private static function unitKey(?string $unit): string
    {
        if (null === $unit) {
            return '';
        }

        return rtrim(trim(self::fold($unit)), '. ');
    }

    /**
     * @return list<array{micros: int, measure: ProductMeasure, multiplier: int}> every size in the text, in order of appearance
     */
    private static function sizesIn(string $text): array
    {
        $text = self::fold($text);
        $patterns = [
            // 6x0,5l, 6 x 0.5 l, 6*0,5l
            '/(?<![\d.,])(?<m>\d{1,3})\s*[x*×]\s*'.self::SIZE.'/u',
            // 0,5l x 6
            '/(?<![\d.,])'.self::SIZE.'\s*[x*×]\s*(?<m>\d{1,3})(?![\d.,a-z])/u',
            // 0,5l
            '/(?<![\d.,])'.self::SIZE.'/u',
        ];

        $used = [];
        $found = [];
        foreach ($patterns as $pattern) {
            if (false === preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) || [] === $matches) {
                continue;
            }
            foreach ($matches as $match) {
                $start = $match[0][1];
                $end = $start + \strlen($match[0][0]);
                foreach ($used as [$usedStart, $usedEnd]) {
                    if ($start < $usedEnd && $end > $usedStart) {
                        continue 2;
                    }
                }
                $used[] = [$start, $end];

                $unit = str_starts_with($match['u'][0], 'litr') ? 'l' : $match['u'][0];
                [$factor, $measure] = self::FACTORS[$unit];
                $micros = self::scaled($match['n'][0], $factor);
                $multiplier = isset($match['m']) ? (int) $match['m'][0] : 1;
                if (null === $micros || $micros <= 0 || $multiplier < 1 || $multiplier > self::MAX_MULTIPLIER || $micros * $multiplier > self::MAX_MICROS) {
                    continue;
                }
                $found[$start] = ['micros' => $micros * $multiplier, 'measure' => $measure, 'multiplier' => $multiplier];
            }
        }
        ksort($found);

        return array_values($found);
    }

    /** `0,7` × the unit's factor in micro-units, exactly; null when it has more decimals than the base unit has or is too large. */
    private static function scaled(string $number, int $factor): ?int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $number), 2), 2, '');
        if (\strlen($whole) > 4 || \strlen($fraction) > 6) {
            return null;
        }
        $micros = (int) $whole * self::SCALE + (int) str_pad($fraction, 6, '0');
        $product = $micros * $factor;
        if (0 !== $product % self::SCALE) {
            return null;
        }

        return intdiv($product, self::SCALE);
    }

    /** Millionths as a decimal string with trailing zeros trimmed: 700000 → "0.7", 1000000 → "1". */
    private static function toDecimal(int $micros): string
    {
        $text = \sprintf('%d.%06d', intdiv($micros, self::SCALE), $micros % self::SCALE);

        return rtrim(rtrim($text, '0'), '.');
    }
}
