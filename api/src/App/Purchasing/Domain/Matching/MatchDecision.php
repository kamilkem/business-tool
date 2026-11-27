<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Purchasing\Domain\Model\PackageSize;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductMeasure;
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
 * The package size is the size of one single package, and, for a bundle (ADR 0048), the count; the rules compare
 * the totals. A size the reading found strongly (the unit is a measure, or one size on a piece unit) wins over what
 * an alias or the model remembered; what the item's own text says is the item's.
 */
final readonly class MatchDecision
{
    /** The size of one single package, or per unit of the quantity when it is no bundle; null when unknown. */
    public ?string $packageSize;

    /** Single packages per unit of the quantity, 2 or more; null when it is no bundle (ADR 0048). */
    public ?int $bundleCount;

    private function __construct(
        public Product $product,
        /** The package size and bundle count together; null when the size is unknown. */
        public ?PackageSize $package,
        public PurchasedItemStatus $status,
        public MatchSource $source,
        /** Why it is `suggested` and not `assigned` (ADR 0045 §1); null for `assigned`. */
        public ?MatchCaveat $caveat,
    ) {
        $this->packageSize = $package?->single;
        $this->bundleCount = $package?->bundleCount;
    }

    /**
     * The strongest alias of the item, `$conflict` when another alias of the item points to another product.
     */
    public static function fromAlias(ProductAlias $alias, bool $conflict, ItemReading $reading): self
    {
        return self::decide($alias->getProduct(), $alias->getPackage(), $conflict ? MatchCaveat::AliasesConflict : null, MatchSource::ofAlias($alias->getKind()), $reading);
    }

    /**
     * The model's answer for the item; null when it is not sure enough to link at all. `$fromProposedName`:
     * the answer is a proposed product whose name a product has (ADR 0044 §3), which is never confident.
     */
    public static function fromInterpretation(Product $product, ItemInterpretation $interpretation, ItemReading $reading, bool $fromProposedName = false): ?self
    {
        if (InterpretationConfidence::Low === $interpretation->confidence) {
            return null;
        }
        // The model's answer is a total per unit of the quantity, never a bundle (ADR 0048 §6); one that is no positive decimal is none.
        $size = null === $interpretation->packageSize ? null : PackageSize::ofTotal($interpretation->packageSize);

        $doubt = match (true) {
            $fromProposedName => MatchCaveat::ProposedNameOnly,
            InterpretationConfidence::High !== $interpretation->confidence => MatchCaveat::ModelUnsure,
            default => null,
        };

        return self::decide($product, $size, $doubt, $fromProposedName ? MatchSource::ProposedName : MatchSource::Model, $reading);
    }

    /** The same match, held back to `suggested` because the price is far from the product's others (ADR 0045 §2). */
    public function withPriceOutlier(): self
    {
        if (PurchasedItemStatus::Assigned !== $this->status) {
            return $this;
        }

        return new self($this->product, $this->package, PurchasedItemStatus::Suggested, $this->source, MatchCaveat::PriceOutlier);
    }

    /** @param ?MatchCaveat $doubt why the source itself is not confident (conflicting aliases, an unsure model); null: it is */
    private static function decide(Product $product, ?PackageSize $remembered, ?MatchCaveat $doubt, MatchSource $source, ItemReading $reading): self
    {
        $measure = $product->getMeasure();
        if (ProductMeasure::None === $measure) {
            return self::result($product, null, $doubt, $source);
        }

        $size = null;
        if ($reading->hasSize() && $reading->measure !== $measure) {
            // The text speaks of litres and the product is counted in kilograms, or the other way round.
            return new self($product, null, PurchasedItemStatus::Suggested, $source, MatchCaveat::MeasureMismatch);
        }
        $read = $reading->size();
        if (null !== $read && $reading->strong) {
            $size = $read;
        } elseif (null !== $remembered) {
            $size = $remembered;
            if (null !== $read) {
                $agrees = $read->sameTotalAs($remembered);
                // A size read on a unit of unknown count (a case, a carton) is not the size per unit, so an
                // alias or the model agreeing with it proves nothing (ADR 0043).
                if (!$agrees || !$reading->unitCountKnown) {
                    $doubt ??= $agrees ? MatchCaveat::SizeUnverified : MatchCaveat::SizeDisagrees;
                }
                // The same total: the breakdown the name states (6 × 0,5 L) is what the person is shown (ADR 0048 §5).
                $size = $agrees ? $read : $remembered;
            }
        } elseif (null !== $read) {
            $size = $read;
            $doubt ??= MatchCaveat::SizeReadWeakly;
        } elseif (ProductMeasure::Count === $measure && $reading->pieceUnit && SizeSource::Ambiguous !== $reading->source) {
            $size = PackageSize::of('1');
        }

        return self::result($product, $size, $doubt, $source);
    }

    private static function result(Product $product, ?PackageSize $size, ?MatchCaveat $doubt, MatchSource $source): self
    {
        $caveat = $doubt ?? (null === $size && ProductMeasure::None !== $product->getMeasure() ? MatchCaveat::SizeUnknown : null);

        return new self($product, $size, null === $caveat ? PurchasedItemStatus::Assigned : PurchasedItemStatus::Suggested, $source, $caveat);
    }
}
