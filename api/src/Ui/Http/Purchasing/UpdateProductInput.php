<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateProductInputProvider pre-fills it from the current
 * product and API Platform applies the request body onto it, so a field the client did not send
 * keeps its value, and `"categoryId": null` takes the product out of its category.
 */
final class UpdateProductInput
{
    /** Trimmed; unique in the organization, compared case-insensitively. */
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: Product::NAME_MAX_LENGTH, normalizer: 'trim')]
    public ?string $name = null;

    /** The category it is in; null for none. */
    #[Assert\Uuid]
    public ?string $categoryId = null;

    /** What the product is counted in. Changing it to `none` needs `referenceQuantity: null` in the same request. */
    #[Assert\NotNull]
    public ?ProductMeasure $measure = null;

    /** The quantity prices are compared per, in the base unit of the measure (L, kg, pc). Null is 1 for a measure with a unit; for `none` it must be null. */
    #[Assert\Regex(pattern: Product::REFERENCE_QUANTITY_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
    #[Assert\Positive]
    public ?string $referenceQuantity = null;

    /** Whether the product and its items are left out of every total. */
    #[Assert\NotNull]
    public ?bool $excluded = null;

    public static function fromView(ProductView $view): self
    {
        $input = new self();
        $input->name = $view->name;
        $input->categoryId = $view->categoryId;
        $input->measure = $view->measure;
        $input->referenceQuantity = $view->referenceQuantity;
        $input->excluded = $view->excluded;

        return $input;
    }
}
