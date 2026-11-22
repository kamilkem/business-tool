<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** A cash storage's name is trimmed and has 1 to 64 characters (ADR 0033 §3). */
final class InvalidStorageNameException extends CashFlowException
{
    public static function of(string $name, int $maxLength): self
    {
        return new self(\sprintf('A cash storage name must have 1 to %d characters, "%s" given.', $maxLength, $name), ['name' => $name, 'maxLength' => $maxLength]);
    }
}
