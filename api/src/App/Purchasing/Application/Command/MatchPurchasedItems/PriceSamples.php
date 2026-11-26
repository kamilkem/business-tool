<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\MatchPurchasedItems;

use App\Purchasing\Domain\Matching\MatchDecision;
use App\Purchasing\Domain\Matching\PriceSanity;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;

/**
 * The price check of one matching run (ADR 0045 §2): holds back an `assigned` decision whose price per base
 * unit is far from the median of the product's confirmed items. The sample of a product and currency is read
 * once per run, however many items of it the run matches.
 */
final class PriceSamples
{
    /** @var array<string, list<int>> */
    private array $samples = [];

    public function __construct(private readonly PurchasedItemRepositoryInterface $items)
    {
    }

    public function check(MatchDecision $decision, PurchasedItem $item): MatchDecision
    {
        if (ProductMeasure::None === $decision->product->getMeasure() || null === $decision->packageSize) {
            return $decision;
        }
        $price = PriceSanity::of($item, $decision->packageSize);
        if (null === $price) {
            return $decision;
        }

        $key = $decision->product->getId()->toRfc4122().'#'.$item->getCurrency();
        $this->samples[$key] ??= $this->items->confirmedPricesPerBaseUnit($decision->product->getId(), $item->getCurrency(), PriceSanity::MAX_SAMPLE);

        return PriceSanity::isOutlier($price, $this->samples[$key]) ? $decision->withPriceOutlier() : $decision;
    }
}
