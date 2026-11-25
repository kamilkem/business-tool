<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

final class InvalidProductNameException extends PurchasingException
{
    public static function of(string $name, int $maxLength): self
    {
        return new self(\sprintf('A product name must have 1 to %d characters, "%s" given.', $maxLength, $name), ['name' => $name, 'maxLength' => $maxLength]);
    }
}
