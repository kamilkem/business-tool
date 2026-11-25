<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemView;
use App\Purchasing\Domain\Model\PurchasedItem;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: ChangePurchasedItemInputProvider pre-fills it from the
 * current item and API Platform applies the request body onto it, so a field the client did not send
 * keeps its value, and `"productId": null` takes the item off its product. Whether the product is
 * the organization's is the domain's (404).
 */
final class ChangePurchasedItemInput
{
    /** The product the item is linked to; null for none. */
    #[Assert\Uuid]
    public ?string $productId = null;

    /** In the product's base unit (L, kg, pc) per one unit of the item's quantity; null for unknown. */
    #[Assert\Regex(pattern: PurchasedItem::PACKAGE_SIZE_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
    #[Assert\Positive]
    public ?string $packageSize = null;

    public static function fromView(PurchasedItemView $view): self
    {
        $input = new self();
        $input->productId = $view->productId;
        $input->packageSize = $view->packageSize;

        return $input;
    }
}
