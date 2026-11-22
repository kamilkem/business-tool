<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Only a register no revenue entry refers to can be deleted; one with entries is deactivated (ADR 0032). */
final class RegisterInUseException extends IncomeException
{
    public static function of(string $registerId, string $name): self
    {
        return new self(\sprintf('The register "%s" has revenue entries; deactivate it instead.', $name), ['registerId' => $registerId, 'name' => $name]);
    }
}
