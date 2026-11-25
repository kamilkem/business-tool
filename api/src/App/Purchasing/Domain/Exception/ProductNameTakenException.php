<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** Product names are unique in the organization, compared case-insensitively. */
final class ProductNameTakenException extends PurchasingException
{
    public static function of(string $name): self
    {
        return new self(\sprintf('The organization already has a product named "%s".', $name), ['name' => $name]);
    }
}
