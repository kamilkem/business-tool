<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Model\Product;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /products. ProductCollectionProvider builds it from the query parameters
 * and validates it, so a bad category id is a 422 with violations like any other Input.
 */
final readonly class ListProductsInput
{
    public function __construct(
        /** Only the products in this category or any of its subcategories. */
        #[Assert\Uuid]
        public ?string $categoryId = null,
        /** Only the products whose name contains this text, case-insensitively. */
        #[Assert\Length(max: Product::NAME_MAX_LENGTH)]
        public ?string $search = null,
    ) {
    }
}
