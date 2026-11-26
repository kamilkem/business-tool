<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** No such proposal in this organization: no item of it proposes the product (another organization's is not found either). */
final class ProductProposalNotFoundException extends PurchasingException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('Product proposal "%s" not found.', $key), ['key' => $key]);
    }
}
