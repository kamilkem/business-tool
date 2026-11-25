<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use Symfony\Component\Validator\Constraints as Assert;

/** Whether the category is the organization's, the name is free and the reference quantity fits the measure is the domain's (409, 422). */
final readonly class CreateProductInput
{
    public function __construct(
        /** Trimmed; unique in the organization, compared case-insensitively. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Product::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name,
        /** What the product is counted in; `none` for what is not goods (rent, electricity, deposits). */
        public ProductMeasure $measure,
        /** The category it is in; null or left out for none. */
        #[Assert\Uuid]
        public ?string $categoryId = null,
        /** The quantity prices are compared per, in the base unit of the measure (L, kg, pc). Null or left out: 1 for a measure with a unit; for `none` it must be null. */
        #[Assert\Regex(pattern: Product::REFERENCE_QUANTITY_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
        #[Assert\Positive]
        public ?string $referenceQuantity = null,
        /** Whether the product and its items are left out of every total. */
        public bool $excluded = false,
    ) {
    }
}
