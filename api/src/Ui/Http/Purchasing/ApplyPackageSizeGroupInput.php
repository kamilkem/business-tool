<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Model\PurchasedItem;
use Symfony\Component\Validator\Constraints as Assert;

/** One group of `GET /products/{id}/package-sizes`, to set the package size on. */
final readonly class ApplyPackageSizeGroupInput
{
    public function __construct(
        /** The group's `unitKey`; null: the items with no unit. */
        #[Assert\Length(max: 64)]
        public ?string $unitKey,
        /** The group's `packageSize`; null: the items with none. */
        #[Assert\Regex(pattern: PurchasedItem::PACKAGE_SIZE_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
        public ?string $packageSize,
    ) {
    }
}
