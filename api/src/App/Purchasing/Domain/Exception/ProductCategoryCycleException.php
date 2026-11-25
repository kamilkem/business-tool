<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** A category cannot move under itself or one of its descendants: the tree would have a cycle. */
final class ProductCategoryCycleException extends PurchasingException
{
    public static function of(string $categoryId, string $parentId): self
    {
        return new self(
            \sprintf('Product category "%s" cannot move under "%s": that is itself or one of its subcategories.', $categoryId, $parentId),
            ['categoryId' => $categoryId, 'parentId' => $parentId],
        );
    }
}
