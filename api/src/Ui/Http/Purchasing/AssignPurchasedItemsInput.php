<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Command\AssignPurchasedItems\AssignPurchasedItemsCommand;
use App\Purchasing\Domain\Model\PurchasedItem;
use Symfony\Component\Validator\Constraints as Assert;

/** Whether the items and the product are the organization's is the domain's (404, nothing changes). */
final readonly class AssignPurchasedItemsInput
{
    /** @param list<string> $itemIds */
    public function __construct(
        /** The items to link: at least one, distinct. */
        #[Assert\Count(min: 1, max: AssignPurchasedItemsCommand::MAX_ITEMS)]
        #[Assert\Unique]
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        public array $itemIds,
        /** The product they are linked to. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $productId,
        /** Every item gets this package size; null or left out: each keeps its own. */
        #[Assert\Regex(pattern: PurchasedItem::PACKAGE_SIZE_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
        #[Assert\Positive]
        public ?string $packageSize = null,
    ) {
    }
}
