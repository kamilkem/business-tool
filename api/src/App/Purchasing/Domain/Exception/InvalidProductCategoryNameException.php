<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

final class InvalidProductCategoryNameException extends PurchasingException
{
    public static function of(string $name, int $maxLength): self
    {
        return new self(\sprintf('A product category name must have 1 to %d characters, "%s" given.', $maxLength, $name), ['name' => $name, 'maxLength' => $maxLength]);
    }
}
