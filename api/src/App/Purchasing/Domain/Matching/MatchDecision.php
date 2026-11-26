<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Expenses\Domain\Model\DecimalString;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;

/**
 * What matching makes of an item (ADR 0042 §3, §5): the product, its package size and whether it is
 * `assigned` (confident) or `suggested` (for a person to confirm). Pure rules over what the aliases,
 * the model and ItemReader said.
 *
 * `assigned` needs a confident source, a package size that is known (or a product with no unit) and
 * nothing that contradicts it. Everything else that has a product is `suggested`:
 * - the size read from the item is of another measure than the product (litres for a mass product);
 * - the size is unknown, or only a weak reading (a multipack) says it, or the sources disagree on it;
 * - the source is a conflict of aliases, or the model is not sure.
 * A size the reading found strongly (the unit is a measure, or one size on a piece unit) wins over what
 * an alias or the model remembered; what the item's own text says is the item's.
 */
final readonly class MatchDecision
{
    private function __construct(
        public Product $product,
        public ?string $packageSize,
        public PurchasedItemStatus $status,
    ) {
    }

    /**
     * The strongest alias of the item, `$conflict` when another alias of the item points to another product.
     */
    public static function fromAlias(ProductAlias $alias, bool $conflict, ItemReading $reading): self
    {
        return self::decide($alias->getProduct(), $alias->getPackageSize(), !$conflict, $reading);
    }

    /** The model's answer for the item; null when it is not sure enough to link at all. */
    public static function fromInterpretation(Product $product, ItemInterpretation $interpretation, ItemReading $reading): ?self
    {
        if (InterpretationConfidence::Low === $interpretation->confidence) {
            return null;
        }
        $size = null === $interpretation->packageSize ? null : DecimalString::normalized($interpretation->packageSize, PurchasedItem::PACKAGE_SIZE_INTEGER_DIGITS, PurchasedItem::PACKAGE_SIZE_SCALE);
        if ('0' === $size || (null !== $size && str_starts_with($size, '-'))) {
            $size = null;
        }

        return self::decide($product, $size, InterpretationConfidence::High === $interpretation->confidence, $reading);
    }

    private static function decide(Product $product, ?string $remembered, bool $confident, ItemReading $reading): self
    {
        $measure = $product->getMeasure();
        if (ProductMeasure::None === $measure) {
            return new self($product, null, $confident ? PurchasedItemStatus::Assigned : PurchasedItemStatus::Suggested);
        }

        $size = null;
        if ($reading->hasSize() && $reading->measure !== $measure) {
            // The text speaks of litres and the product is counted in kilograms, or the other way round.
            return new self($product, null, PurchasedItemStatus::Suggested);
        }
        if ($reading->hasSize() && $reading->strong) {
            $size = $reading->packageSize;
        } elseif (null !== $remembered) {
            $size = $remembered;
            if ($reading->hasSize() && $reading->packageSize !== $remembered) {
                $confident = false;
            }
        } elseif ($reading->hasSize()) {
            $size = $reading->packageSize;
            $confident = false;
        } elseif (ProductMeasure::Count === $measure && $reading->pieceUnit && SizeSource::Ambiguous !== $reading->source) {
            $size = '1';
        }

        return new self($product, $size, $confident && null !== $size ? PurchasedItemStatus::Assigned : PurchasedItemStatus::Suggested);
    }
}
