<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Model\ProductCategory;
use Symfony\Component\Validator\Constraints as Assert;

/** Whether the parent is the organization's and the name is free among its subcategories is the domain's (404, 409). */
final readonly class AddProductCategoryInput
{
    public function __construct(
        /** Trimmed; unique among the subcategories of the same parent, or among the top level categories, compared case-insensitively. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: ProductCategory::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name,
        /** The category it goes under; null or left out for the top level. */
        #[Assert\Uuid]
        public ?string $parentId = null,
    ) {
    }
}
