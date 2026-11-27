<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Command\AssignPurchasedItems\AssignPurchasedItemsCommand;
use App\Purchasing\Domain\Model\PackageSize;
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
        /** Every item gets this package size, and the bundle count below; null or left out: each keeps its own size and count. */
        #[Assert\Regex(pattern: PurchasedItem::PACKAGE_SIZE_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
        #[Assert\Positive]
        public ?string $packageSize = null,
        /** Single packages per unit of the quantity for every item that gets the package size; null: no bundle. Needs a package size. */
        #[Assert\Range(min: PackageSize::MIN_BUNDLE_COUNT, max: PackageSize::MAX_BUNDLE_COUNT)]
        public ?int $bundleCount = null,
    ) {
    }
}
