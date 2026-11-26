<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use Symfony\Component\Validator\Constraints as Assert;

/** What the product is created with: the proposal's name, measure and category, as the person edited them. Whether the category is the organization's, the name is free and the reference quantity fits is the domain's (409, 422). */
final readonly class AcceptProductProposalInput
{
    public function __construct(
        /** Trimmed; unique in the organization, compared case-insensitively. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Product::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name,
        /** What the product is counted in; `none` for what is not goods. */
        public ProductMeasure $measure,
        /** The category it is in; null or left out for none. */
        #[Assert\Uuid]
        public ?string $categoryId = null,
        /** The quantity prices are compared per, in the base unit of the measure; null or left out: 1 for a measure with a unit, null for `none`. */
        #[Assert\Regex(pattern: Product::REFERENCE_QUANTITY_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
        #[Assert\Positive]
        public ?string $referenceQuantity = null,
    ) {
    }
}
