<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use App\Purchasing\Domain\Model\ProductCategory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateProductCategoryInputProvider pre-fills it from the
 * current category and API Platform applies the request body onto it, so a field the client did not
 * send keeps its value, and `"parentId": null` moves the category to the top level.
 */
final class UpdateProductCategoryInput
{
    /** Trimmed; unique among the subcategories of the (new) parent, compared case-insensitively. */
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: ProductCategory::NAME_MAX_LENGTH, normalizer: 'trim')]
    public ?string $name = null;

    /** The category it goes under, never itself or one of its own subcategories; null for the top level. */
    #[Assert\Uuid]
    public ?string $parentId = null;

    public static function fromView(ProductCategoryView $view): self
    {
        $input = new self();
        $input->name = $view->name;
        $input->parentId = $view->parentId;

        return $input;
    }
}
