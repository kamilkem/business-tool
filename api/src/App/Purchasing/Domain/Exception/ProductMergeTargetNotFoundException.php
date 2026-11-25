<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** The product a merge goes into is not one of this organization's (another organization's is not found either). */
final class ProductMergeTargetNotFoundException extends PurchasingException
{
    public static function withId(string $targetId): self
    {
        return new self(\sprintf('The product to merge into, "%s", was not found.', $targetId), ['targetId' => $targetId]);
    }
}
